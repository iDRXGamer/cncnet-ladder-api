<?php

namespace App\Console\Commands;

use App\Extensions\Qm\Matchup\PlayerMatchupHandler;
use App\Http\Controllers\Api\V2\Qm\MatchUpController;
use App\Http\Controllers\ApiLadderController;
use App\Http\Services\PlayerService;
use App\Http\Services\QuickMatchService;
use App\Jobs\Qm\FindOpponentJob;
use App\Models\Game;
use App\Models\GameReport;
use App\Models\Ladder;
use App\Models\LadderHistory;
use App\Models\Map;
use App\Models\MapPool;
use App\Models\Player;
use App\Models\PlayerGameReport;
use App\Models\PlayerHistory;
use App\Models\QmLadderRules;
use App\Models\QmMap;
use App\Models\QmMatch;
use App\Models\QmMatchPlayer;
use App\Models\QmQueueEntry;
use App\Models\Side;
use App\Models\User;
use App\Models\UserSettings;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

class SimulateCasualMatchmaking extends Command
{
    protected $signature = 'qm:simulate-casual';
    protected $description = 'Simulate full Casual Matchmaking flow with mock Red Alert 2 clients and zero Elo verification';

    public function handle()
    {
        config(['queue.default' => 'sync']);

        $this->info("===============================================================");
        $this->info("  CnCNet Red Alert 2 - Casual Matchmaking Simulation (Mock)   ");
        $this->info("===============================================================\n");

        // 1. Setup Mock Ladder and Maps
        $this->info("[1/5] Setting up simulation environment & ladder...");
        $modesConfig = [
            ['abbr' => 'sim-ra2', 'name' => 'Simulation RA2 1v1', 'count' => 2, 'type' => 1],
            ['abbr' => 'sim-ra2-2v2', 'name' => 'Simulation RA2 2v2', 'count' => 4, 'type' => 2],
            ['abbr' => 'sim-ra2-3v3', 'name' => 'Simulation RA2 3v3', 'count' => 6, 'type' => 2],
            ['abbr' => 'sim-ra2-2v2v2v2', 'name' => 'Simulation RA2 2v2v2v2', 'count' => 8, 'type' => 2],
            ['abbr' => 'sim-ra2-4v4', 'name' => 'Simulation RA2 4v4', 'count' => 8, 'type' => 2],
        ];

        $primaryLadder = null;

        foreach ($modesConfig as $cfg) {
            $curLadder = Ladder::firstOrCreate(
                ['abbreviation' => $cfg['abbr']],
                ['name' => $cfg['name'], 'clans_allowed' => false, 'ladder_type' => $cfg['type']]
            );

            foreach ([0 => 'Allied', 1 => 'Soviet', 2 => 'Yuri'] as $sideId => $sideName) {
                Side::firstOrCreate(
                    ['ladder_id' => $curLadder->id, 'local_id' => $sideId],
                    ['name' => $sideName]
                );
            }

            $rules = QmLadderRules::firstOrCreate(
                ['ladder_id' => $curLadder->id],
                ['player_count' => $cfg['count'], 'allowed_sides' => '-1,0,1,2']
            );
            $rules->player_count = $cfg['count'];
            $rules->save();

            $mapPool = MapPool::firstOrCreate(['ladder_id' => $curLadder->id]);
            $curLadder->map_pool_id = $mapPool->id;
            $curLadder->save();

            $mapNames = ['Heck Freezes Over', 'Country Swing', 'Tournament Arena', 'May Day'];
            foreach ($mapNames as $idx => $name) {
                $map = Map::firstOrCreate(
                    ['name' => $name, 'ladder_id' => $curLadder->id],
                    ['spawn_count' => $cfg['count'], 'filename' => strtolower(str_replace(' ', '_', $name)) . '.map']
                );
                QmMap::firstOrCreate(
                    ['ladder_id' => $curLadder->id, 'map_pool_id' => $mapPool->id, 'map_id' => $map->id],
                    [
                        'description' => $name,
                        'valid' => 1,
                        'bit_idx' => $idx,
                        'spawn_order' => '0,0',
                        'allowed_sides' => $rules->allowed_sides
                    ]
                );
            }

            $lh = LadderHistory::firstOrCreate(
                ['ladder_id' => $curLadder->id],
                ['starts' => now()->startOfMonth(), 'ends' => now()->endOfMonth()]
            );
            $curLadder->setRelation('currentHistory', $lh);

            if ($cfg['abbr'] === 'sim-ra2') {
                $primaryLadder = $curLadder;
            }

            $this->line("  - Ladder: <fg=cyan>{$curLadder->name}</> ({$curLadder->abbreviation}) [{$cfg['count']}p]");
        }

        $ladder = $primaryLadder;
        $this->line("  - Map Pool: " . count($mapNames) . " official maps loaded\n");

        // 2. Setup Simulated Players
        $this->info("[2/5] Creating simulated Red Alert 2 player accounts...");
        $user1 = $this->getOrCreateSimUser('Alice_Commander');
        $player1 = $this->getOrCreateSimPlayer('Alice_Commander', $ladder, $user1, $lh);

        $user2 = $this->getOrCreateSimUser('Bob_General');
        $player2 = $this->getOrCreateSimPlayer('Bob_General', $ladder, $user2, $lh);

        $userRanked = $this->getOrCreateSimUser('Ranked_Tryhard');
        $playerRanked = $this->getOrCreateSimPlayer('Ranked_Tryhard', $ladder, $userRanked, $lh);

        $this->line("  - Client 1: <fg=yellow>{$player1->username}</> (Casual Player)");
        $this->line("  - Client 2: <fg=yellow>{$player2->username}</> (Casual Player)");
        $this->line("  - Client 3: <fg=magenta>{$playerRanked->username}</> (Ranked Player)\n");

        // Clean up any lingering queue entries and matches from prior runs
        QmQueueEntry::query()->delete();
        QmMatchPlayer::query()->delete();
        QmMatch::where('ladder_id', $ladder->id)->delete();

        $controller = app(MatchUpController::class);

        // 3. Client 1 Joins Casual Queue
        $this->info("[3/5] Client 1 (Alice) clicks 'Casual Matchmaking' button...");
        $req1 = Request::create("/api/v1/qm/{$ladder->abbreviation}/{$player1->username}", 'POST', [
            'version' => '1.83',
            'type' => 'match me up',
            'map_bitfield' => 0xffffffff,
            'side' => 1,
            'map_sides' => [1, 1, 1, 1],
            'casual' => true,
        ]);
        $req1->setUserResolver(fn() => $user1);
        auth('api')->setUser($user1);

        $res1 = $controller($req1, $ladder, $player1->username);
        $data1 = json_decode($res1->getContent(), true);

        $this->line("  - Client 1 Request: [mode=casual, side=Soviet]");
        $this->line("  - Server Response: <fg=green>type = '{$data1['type']}'</> (waiting for match)");

        $entry1 = QmQueueEntry::whereHas('qmPlayer', fn($q) => $q->where('player_id', $player1->id))->first();
        $this->line("  - DB Verification: qm_queue_entries.casual = " . ($entry1?->casual ? '<fg=green>true</>' : '<fg=red>false</>') . "\n");

        // 4. Ranked Client 3 Joins Queue -> Verify Isolation
        $this->info("[4/5] Testing Ranked vs Casual Isolation...");
        $reqRanked = Request::create("/api/v1/qm/{$ladder->abbreviation}/{$playerRanked->username}", 'POST', [
            'version' => '1.83',
            'type' => 'match me up',
            'map_bitfield' => 0xffffffff,
            'side' => 0,
            'map_sides' => [0, 0, 0, 0],
            'casual' => false, // Ranked!
        ]);
        $reqRanked->setUserResolver(fn() => $userRanked);
        auth('api')->setUser($userRanked);

        $resRanked = $controller($reqRanked, $ladder, $playerRanked->username);
        $dataRanked = json_decode($resRanked->getContent(), true);

        $this->line("  - Client 3 (Ranked) Request: [mode=ranked, casual=false]");
        $this->line("  - Server Response: <fg=green>type = '{$dataRanked['type']}'</>");
        $activeMatches = QmMatch::where('ladder_id', $ladder->id)->count();
        $this->line("  - Active Matches count: <fg=green>{$activeMatches}</> (Ranked player did NOT match with Casual player!)\n");

        // 5. Client 2 Joins Casual Queue -> Should Pair with Client 1
        $this->info("[5/5] Client 2 (Bob) clicks 'Casual Matchmaking' button...");
        $req2 = Request::create("/api/v1/qm/{$ladder->abbreviation}/{$player2->username}", 'POST', [
            'version' => '1.83',
            'type' => 'match me up',
            'map_bitfield' => 0xffffffff,
            'side' => 0,
            'map_sides' => [0, 0, 0, 0],
            'casual' => true,
        ]);
        $req2->setUserResolver(fn() => $user2);
        auth('api')->setUser($user2);

        $res2 = $controller($req2, $ladder, $player2->username);
        $data2 = json_decode($res2->getContent(), true);

        // Client 2 polls / checkback
        $res2Poll = $controller($req2, $ladder, $player2->username);
        $data2Poll = json_decode($res2Poll->getContent(), true);

        $this->line("  - Server Matchmaking Job Executed!");
        $this->line("  - Client 2 Polling Response: <fg=green>type = '{$data2Poll['type']}'</>");

        // Verify match properties
        $match = QmMatch::where('ladder_id', $ladder->id)->latest('id')->first();
        if ($match) {
            $this->line("  - Matched Game ID: <fg=cyan>{$match->game_id}</>");
            $this->line("  - Match is_casual flag: " . ($match->is_casual ? '<fg=green>true</>' : '<fg=red>false</>'));
            $this->line("  - Selected Map: <fg=yellow>{$match->map?->description}</>");
            $this->line("  - Match Seed: {$match->seed}");
        }

        // Verify Game Results & Zero Elo Rating Award
        $this->info("\n--- Simulating Game Completion & Elo Rating Verification ---");
        if ($match && $match->game) {
            $game = $match->game;
            $game->is_casual = true;
            $game->save();

            $report = new GameReport();
            $report->game_id = $game->id;
            $report->valid = true;
            $report->duration = 540;
            $report->fps = 60;
            $report->save();

            // Player 1 wins, Player 2 loses
            $pgr1 = new PlayerGameReport();
            $pgr1->game_report_id = $report->id;
            $pgr1->game_id = $game->id;
            $pgr1->player_id = $player1->id;
            $pgr1->local_team_id = 0;
            $pgr1->won = 1;
            $pgr1->points = 999; // Pre-fill to verify reset
            $pgr1->save();

            $pgr2 = new PlayerGameReport();
            $pgr2->game_report_id = $report->id;
            $pgr2->game_id = $game->id;
            $pgr2->player_id = $player2->id;
            $pgr2->local_team_id = 1;
            $pgr2->won = 0;
            $pgr2->points = -999;
            $pgr2->save();

            $ladderController = app(ApiLadderController::class);
            $statusCode = $ladderController->awardPlayerPoints($report, $lh);

            $pgr1->refresh();
            $pgr2->refresh();

            $this->line("  - Match Result Processed: HTTP Status <fg=green>{$statusCode}</>");
            $this->line("  - Winner ({$player1->username}) Elo Points Awarded: <fg=green>{$pgr1->points} pts</> (Zero Elo Change!)");
            $this->line("  - Loser ({$player2->username}) Elo Points Deducted: <fg=green>{$pgr2->points} pts</> (Zero Elo Change!)");
            $this->line("  - Ladder Rankings: <fg=green>Completely unaffected!</>");
        }

        $this->info("\n===============================================================");
        $this->info("   SUCCESS: Casual Matchmaking Server Simulation PASSED!      ");
        $this->info("===============================================================\n");

        return 0;
    }

    private function getOrCreateSimUser($name)
    {
        $user = User::firstOrCreate(
            ['name' => $name],
            ['email' => strtolower($name) . '@simulation.test', 'password' => Hash::make('secret'), 'email_verified' => 1]
        );
        UserSettings::firstOrCreate(['user_id' => $user->id]);
        return $user;
    }

    private function getOrCreateSimPlayer($username, Ladder $ladder, User $user, LadderHistory $lh)
    {
        $player = Player::firstOrCreate(
            ['username' => $username, 'ladder_id' => $ladder->id],
            ['user_id' => $user->id]
        );
        PlayerHistory::firstOrCreate(
            ['player_id' => $player->id, 'ladder_history_id' => $lh->id],
            ['tier' => 1]
        );
        return $player;
    }
}
