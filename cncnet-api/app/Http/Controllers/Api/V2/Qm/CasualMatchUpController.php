<?php

namespace App\Http\Controllers\Api\V2\Qm;

use App\Http\Services\CasualMatchmakingService;
use App\Http\Services\PlayerService;
use App\Http\Services\QuickMatchService;
use App\Jobs\Qm\FindOpponentJob;
use App\Models\Game;
use App\Models\Ladder;
use App\Models\Player;
use App\Models\QmMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Casual matchmaking requests from the CnCNet client. Casual players do not log in and
 * can only queue on casual ladders; ranked quick match is handled by MatchUpController.
 */
class CasualMatchUpController
{
    private CasualMatchmakingService $casualService;
    private PlayerService $playerService;
    private QuickMatchService $quickMatchService;

    public function __construct(
        CasualMatchmakingService $casualService,
        PlayerService $playerService,
        QuickMatchService $quickMatchService
    )
    {
        $this->casualService = $casualService;
        $this->playerService = $playerService;
        $this->quickMatchService = $quickMatchService;
    }

    public function __invoke(Request $request, Ladder $ladder, string $playerName): JsonResponse
    {
        if (!$ladder->is_casual)
        {
            return $this->quickMatchService->onFatalError('Casual matchmaking is not available on ' . $ladder->abbreviation);
        }

        $player = $this->playerService->findPlayerByUsername($playerName, $ladder);

        // Players that belong to a registered account can only be used by that account, which cannot log in here
        if ($player !== null && !$this->casualService->isCasualAccount($player->user))
        {
            return $this->quickMatchService->onFatalError(
                $playerName . ' is already used by a registered player on ' . $ladder->abbreviation . '. Please choose another name.'
            );
        }

        if ($request->input('type') === 'quit')
        {
            if ($player !== null)
            {
                $this->casualService->leaveQueue($player);
            }

            return response()->json(['type' => 'quit']);
        }

        if ($player === null)
        {
            if (!$this->casualService->isValidPlayerName($playerName))
            {
                return $this->quickMatchService->onFatalError(
                    'Player names must be at most 16 characters long, may only contain letters, numbers and -_[]{}^`|\\ and cannot start with a number or hyphen'
                );
            }

            $player = $this->casualService->createPlayer($ladder, $playerName);
        }

        $ban = $this->playerService->checkUserForBans($player->user, $request->getClientIp(), $request->hwid);
        if (isset($ban))
        {
            return $this->quickMatchService->onFatalError($ban);
        }

        if ($request->input('type') === 'match me up')
        {
            return $this->onMatchMeUp($request, $ladder, $player);
        }

        return response()->json([
            'type' => 'error',
            'description' => 'unknown type: ' . $request->input('type'),
        ]);
    }

    private function onMatchMeUp(Request $request, Ladder $ladder, Player $player): JsonResponse
    {
        $qmPlayer = $this->casualService->findWaitingQmPlayer($player);

        if ($qmPlayer === null)
        {
            $qmPlayer = $this->casualService->createQmPlayer($request, $player, $ladder);
            if ($qmPlayer === null)
            {
                return $this->quickMatchService->onFatalError('Side (' . $request->input('side') . ') is not allowed');
            }
        }

        if ($qmPlayer->qm_match_id === null)
        {
            $gameType = $ladder->qmLadderRules->player_count > 2 ? Game::GAME_TYPE_2VS2 : Game::GAME_TYPE_1VS1;
            $qmQueueEntry = $this->quickMatchService->createOrUpdateQueueEntry($player, $qmPlayer, $ladder->current_history, $gameType);

            dispatch(new FindOpponentJob($qmQueueEntry->id, $gameType));

            // The job may have matched this player already, then the spawn is sent right away
            $qmPlayer->refresh();
            if ($qmPlayer->qm_match_id === null)
            {
                $qmPlayer->touch();

                return response()->json([
                    'type' => 'please wait',
                    'checkback' => CasualMatchmakingService::CHECKBACK_SECONDS,
                    'no_sooner_than' => CasualMatchmakingService::CHECKBACK_SECONDS,
                ]);
            }
        }

        $qmMatch = QmMatch::find($qmPlayer->qm_match_id);
        $spawnStruct = $this->casualService->createSpawnStruct($qmMatch, $qmPlayer, $ladder);

        $qmPlayer->waiting = false;
        $qmPlayer->save();

        return response()->json($spawnStruct);
    }

    public function queueCounts(): JsonResponse
    {
        return response()->json($this->casualService->getQueueCounts());
    }
}
