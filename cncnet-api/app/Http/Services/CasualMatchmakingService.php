<?php

namespace App\Http\Services;

use App\Models\Ladder;
use App\Models\Player;
use App\Models\QmMatch;
use App\Models\QmMatchPlayer;
use App\Models\QmQueueEntry;
use App\Models\User;
use App\Models\UserSettings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Casual matchmaking on casual ladders. Casual players do not log in: they play under their
 * CnCNet nickname with a casual account that is separate from any registered account.
 * Matches are created by CasualMatchupHandler and spawn.ini is built with the regular
 * QuickMatchSpawnService functions.
 */
class CasualMatchmakingService
{
    /**
     * Casual clients poll this often. Queue entries that are not updated for STALE_ENTRY_SECONDS
     * belong to clients that left without quitting and are not matched anymore.
     */
    public const CHECKBACK_SECONDS = 5;
    public const STALE_ENTRY_SECONDS = 10;

    /**
     * Casual accounts use the reserved .invalid domain, so they can never receive mail
     * and cannot be mistaken for registered accounts.
     */
    private const CASUAL_ACCOUNT_EMAIL_SUFFIX = '@casual.invalid';

    /**
     * Same rules as the client's NameValidator: at most 16 characters and no digit or hyphen as the first character.
     */
    private const PLAYER_NAME_PATTERN = '/^[a-zA-Z_\[\]\{\}\^`|\\\\][a-zA-Z0-9_\-\[\]\{\}\^`|\\\\]{0,15}$/';

    private const QUEUE_COUNTS_CACHE_KEY = 'qm_casual_queue_counts';

    private QuickMatchService $quickMatchService;
    private PlayerService $playerService;
    private CasualTunnelService $tunnelService;

    public function __construct(QuickMatchService $quickMatchService, PlayerService $playerService, CasualTunnelService $tunnelService)
    {
        $this->quickMatchService = $quickMatchService;
        $this->playerService = $playerService;
        $this->tunnelService = $tunnelService;
    }

    public function isValidPlayerName(string $playerName): bool
    {
        return preg_match(self::PLAYER_NAME_PATTERN, $playerName) === 1;
    }

    public function isCasualAccount(User $user): bool
    {
        return str_ends_with(strtolower($user->email), self::CASUAL_ACCOUNT_EMAIL_SUFFIX);
    }

    /**
     * Creates a casual player on a casual ladder. Every casual name gets its own casual account,
     * even if a registered account uses the same name, so registered accounts are never used.
     */
    public function createPlayer(Ladder $ladder, string $playerName): Player
    {
        $user = User::firstOrCreate(
            ['email' => strtolower($playerName) . self::CASUAL_ACCOUNT_EMAIL_SUFFIX],
            ['name' => $playerName, 'password' => bcrypt(Str::random(32))]
        );

        $player = new Player();
        $player->username = $playerName;
        $player->ladder_id = $ladder->id;
        $player->user_id = $user->id;
        $player->save();

        return $player;
    }

    public function findWaitingQmPlayer(Player $player): ?QmMatchPlayer
    {
        return QmMatchPlayer::where('player_id', $player->id)->where('waiting', true)->first();
    }

    /**
     * Creates the quick match player for a casual queue request.
     * Returns null if the requested side is not allowed on the ladder.
     */
    public function createQmPlayer(Request $request, Player $player, Ladder $ladder): ?QmMatchPlayer
    {
        $side = (int)$request->input('side', 0);

        // Casual clients have no map preferences and cannot determine their public address.
        // map_sides has one entry per map slot (bit_idx) of the map pool.
        $mapSlotCount = max(1, (int)$ladder->mapPool->maps->max('bit_idx') + 1);
        $request->merge([
            'ip_address' => isset($_SERVER["HTTP_CF_CONNECTING_IP"]) ? $_SERVER["HTTP_CF_CONNECTING_IP"] : $request->getClientIp(),
            'map_bitfield' => 0xffffffff,
            'map_sides' => array_fill(0, $mapSlotCount, $side),
        ]);

        // Quick match and spawn.ini generation read the user settings, which casual accounts do not have yet
        UserSettings::firstOrCreate(['user_id' => $player->user_id]);

        $this->playerService->setActiveUsername($player, $ladder);
        $this->playerService->createPlayerRatingIfNull($player);

        $qmPlayer = $this->quickMatchService->createQMPlayer($request, $player, $ladder->current_history);

        if (!$this->quickMatchService->checkPlayerSidesAreValid($qmPlayer, $side, $ladder->qmLadderRules))
        {
            $qmPlayer->delete();
            return null;
        }

        $qmPlayer->save();

        return $qmPlayer;
    }

    /**
     * Removes the player from the queue. Players that were already matched are not affected.
     */
    public function leaveQueue(Player $player): void
    {
        $waitingQmPlayerIds = QmMatchPlayer::where('player_id', $player->id)
            ->whereNull('qm_match_id')
            ->pluck('id');

        QmQueueEntry::whereIn('qm_match_player_id', $waitingQmPlayerIds)->delete();
        QmMatchPlayer::whereIn('id', $waitingQmPlayerIds)->delete();

        Cache::forget(self::QUEUE_COUNTS_CACHE_KEY);
    }

    /**
     * Number of players waiting on each casual ladder, keyed by ladder abbreviation.
     */
    public function getQueueCounts(): array
    {
        return Cache::remember(self::QUEUE_COUNTS_CACHE_KEY, 1, function ()
        {
            $counts = QmQueueEntry::where('qm_queue_entries.updated_at', '>=', Carbon::now()->subSeconds(self::STALE_ENTRY_SECONDS))
                ->join('ladder_history', 'qm_queue_entries.ladder_history_id', '=', 'ladder_history.id')
                ->join('ladders', 'ladder_history.ladder_id', '=', 'ladders.id')
                ->where('ladders.is_casual', true)
                ->selectRaw('ladders.abbreviation as ladder, count(*) as count')
                ->groupBy('ladders.abbreviation')
                ->pluck('count', 'ladder');

            return Ladder::where('is_casual', true)
                ->pluck('abbreviation')
                ->mapWithKeys(fn($abbreviation) => [$abbreviation => (int)($counts[$abbreviation] ?? 0)])
                ->all();
        });
    }

    /**
     * Builds the spawn.ini for a player of a casual match.
     */
    public function createSpawnStruct(QmMatch $qmMatch, QmMatchPlayer $qmPlayer, Ladder $ladder): array
    {
        $otherQmMatchPlayers = $qmMatch->players()
            ->where('id', '<>', $qmPlayer->id)
            ->orderBy('color', 'ASC')
            ->get();

        $spawnStruct = QuickMatchSpawnService::createSpawnStruct($qmMatch, $qmPlayer, $ladder, $ladder->qmLadderRules);
        $spawnStruct = QuickMatchSpawnService::appendOthersToSpawnIni($spawnStruct, $qmPlayer, $otherQmMatchPlayers);
        $spawnStruct["spawn"]["Settings"]["PlayerCount"] = $ladder->qmLadderRules->player_count;

        $allPlayers = $otherQmMatchPlayers->concat([$qmPlayer]);

        // Countries and colors are assigned by the server
        foreach ($allPlayers as $player)
        {
            $multiIndex = $player->color + 1;
            $spawnStruct["spawn"]["HouseCountries"]["Multi{$multiIndex}"] = $player->actual_side;
            $spawnStruct["spawn"]["HouseColors"]["Multi{$multiIndex}"] = $player->color;
        }

        $spawnStruct = self::appendTeamAlliances($spawnStruct, $allPlayers);

        $tunnel = $this->tunnelService->getTunnel($qmMatch->id);
        if ($tunnel !== null)
        {
            $spawnStruct["spawn"]["Tunnel"] = ["Ip" => $tunnel['ip'], "Port" => (int)$tunnel['port']];

            // 0.0.0.0 makes the spawner reach the other players through the tunnel
            foreach (array_keys($spawnStruct["spawn"]) as $section)
            {
                if (str_starts_with($section, "Other"))
                {
                    $spawnStruct["spawn"][$section]["Ip"] = "0.0.0.0";
                }
            }
        }

        return $spawnStruct;
    }

    /**
     * Allies every player with all of their teammates. Each ally gets its own HouseAlly key,
     * so teams of any size are supported.
     */
    private static function appendTeamAlliances(array $spawnStruct, $players): array
    {
        $allyKeys = ["HouseAllyOne", "HouseAllyTwo", "HouseAllyThree", "HouseAllyFour", "HouseAllyFive", "HouseAllySix", "HouseAllySeven"];

        foreach ($players->groupBy('team') as $team => $teamPlayers)
        {
            // 1v1 players have no team
            if (empty($team))
                continue;

            foreach ($teamPlayers as $player)
            {
                $multiIndex = $player->color + 1;
                $allies = $teamPlayers->filter(fn($ally) => $ally->id !== $player->id)->values();

                foreach ($allies as $allyIndex => $ally)
                {
                    $spawnStruct["spawn"]["Multi{$multiIndex}_Alliances"][$allyKeys[$allyIndex]] = $ally->color;
                }
            }
        }

        return $spawnStruct;
    }
}
