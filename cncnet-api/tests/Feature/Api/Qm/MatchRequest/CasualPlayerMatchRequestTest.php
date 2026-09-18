<?php

namespace Tests\Feature\Api\Qm\MatchRequest;

use App\Http\Controllers\ApiLadderController;
use App\Models\Game;
use App\Models\GameReport;
use App\Models\PlayerGameReport;
use App\Models\QmMatch;
use App\Models\QmQueueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Auth\JwtAuthHelper;
use Tests\TestCase;

class CasualPlayerMatchRequestTest extends TestCase
{
    use RefreshDatabase;
    use QmPlayerHelper;
    use JwtAuthHelper;

    private $user1;
    private $player1;
    private $ladder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user1 = $this->makeUser('casual_user1');
        $this->ladder = $this->makeLadder(2);
        $this->player1 = $this->makePlayerForLadder('casual_player1', $this->ladder, $this->user1);
    }

    public function test_casual_match_me_up_on_empty_queue(): void
    {
        $ladderName = $this->ladder->abbreviation;
        $playerName = $this->player1->username;

        $lh = $this->makeLadderHistory($this->ladder);
        $this->makePlayerHistory($this->player1, $lh);

        $response = $this
            ->jwtAuth($this->user1)
            ->post('/api/v1/qm/' . $ladderName . '/' . $playerName, [
                'version' => '1.83',
                'type' => 'match me up',
                'map_bitfield' => 0xffffffff,
                'side' => 1,
                'casual' => true,
            ]);

        $json = $response->json();
        $this->assertEquals('please wait', $json['type']);

        $entry = QmQueueEntry::first();
        $this->assertNotNull($entry);
        $this->assertTrue((bool)$entry->casual);
    }

    public function test_casual_players_match_together_and_spawn(): void
    {
        $d = Carbon::create(2026, 4, 20, 10, 10, 0);
        Carbon::setTestNow($d);

        $ladderName = $this->ladder->abbreviation;
        $lh = $this->makeLadderHistory($this->ladder);
        $this->makePlayerHistory($this->player1, $lh);

        $user2 = $this->makeUser('casual_user2');
        $player2 = $this->makePlayerForLadder('casual_player2', $this->ladder, $user2);
        $this->makePlayerHistory($player2, $lh);

        // Player 1 enters casual queue
        $this
            ->jwtAuth($this->user1)
            ->post('/api/v1/qm/' . $ladderName . '/' . $this->player1->username, [
                'version' => '1.83',
                'type' => 'match me up',
                'map_bitfield' => 0xffffffff,
                'side' => 1,
                'map_sides' => [1, 1, 1, 1],
                'casual' => true,
            ]);

        Carbon::setTestNow($d->clone()->addSeconds(8));

        // Player 2 enters casual queue and matches
        $response = $this
            ->jwtAuth($user2)
            ->post('/api/v1/qm/' . $ladderName . '/' . $player2->username, [
                'version' => '1.83',
                'type' => 'match me up',
                'map_bitfield' => 0xffffffff,
                'side' => 1,
                'map_sides' => [1, 1, 1, 1],
                'casual' => true,
            ]);

        $json = $response->json();
        $this->assertEquals('spawn', $json['type'], json_encode($json));

        $match = QmMatch::first();
        $this->assertNotNull($match);
        $this->assertTrue((bool)$match->is_casual);

        $game = Game::first();
        $this->assertNotNull($game);
        $this->assertTrue((bool)$game->is_casual);
    }

    public function test_casual_and_ranked_players_do_not_match(): void
    {
        $d = Carbon::create(2026, 4, 20, 10, 10, 0);
        Carbon::setTestNow($d);

        $ladderName = $this->ladder->abbreviation;
        $lh = $this->makeLadderHistory($this->ladder);
        $this->makePlayerHistory($this->player1, $lh);

        $user2 = $this->makeUser('ranked_user2');
        $player2 = $this->makePlayerForLadder('ranked_player2', $this->ladder, $user2);
        $this->makePlayerHistory($player2, $lh);

        // Player 1 enters CASUAL queue
        $this
            ->jwtAuth($this->user1)
            ->post('/api/v1/qm/' . $ladderName . '/' . $this->player1->username, [
                'version' => '1.83',
                'type' => 'match me up',
                'map_bitfield' => 0xffffffff,
                'side' => 1,
                'map_sides' => [1, 1, 1, 1],
                'casual' => true,
            ]);

        Carbon::setTestNow($d->clone()->addSeconds(8));

        // Player 2 enters RANKED queue (casual = false)
        $response = $this
            ->jwtAuth($user2)
            ->post('/api/v1/qm/' . $ladderName . '/' . $player2->username, [
                'version' => '1.83',
                'type' => 'match me up',
                'map_bitfield' => 0xffffffff,
                'side' => 1,
                'map_sides' => [1, 1, 1, 1],
                'casual' => false,
            ]);

        $json = $response->json();
        // Should NOT match Player 1, should return 'please wait'
        $this->assertEquals('please wait', $json['type']);
        $this->assertEquals(0, QmMatch::count());
    }

    public function test_casual_game_result_awards_zero_elo(): void
    {
        $lh = $this->makeLadderHistory($this->ladder);
        $this->makePlayerHistory($this->player1, $lh);

        $game = new Game();
        $game->ladder_history_id = $lh->id;
        $game->game_type = Game::GAME_TYPE_1VS1;
        $game->is_casual = true;
        $game->hash = 'test_hash';
        $game->save();

        $gameReport = new GameReport();
        $gameReport->game_id = $game->id;
        $gameReport->valid = true;
        $gameReport->duration = 300;
        $gameReport->fps = 60;
        $gameReport->save();

        $pgr = new PlayerGameReport();
        $pgr->game_report_id = $gameReport->id;
        $pgr->game_id = $game->id;
        $pgr->player_id = $this->player1->id;
        $pgr->local_team_id = 0;
        $pgr->points = 100; // pre-set points to ensure it resets to 0
        $pgr->save();

        $controller = app(ApiLadderController::class);
        $status = $controller->awardPlayerPoints($gameReport, $lh);

        $this->assertEquals(200, $status);
        $pgr->refresh();
        $this->assertEquals(0, $pgr->points);
    }

    public function test_casual_team_game_result_awards_zero_elo(): void
    {
        $lh = $this->makeLadderHistory($this->ladder);
        $this->makePlayerHistory($this->player1, $lh);

        $game = new Game();
        $game->ladder_history_id = $lh->id;
        $game->game_type = Game::GAME_TYPE_2VS2;
        $game->is_casual = true;
        $game->hash = 'test_team_hash';
        $game->save();

        $gameReport = new GameReport();
        $gameReport->game_id = $game->id;
        $gameReport->valid = true;
        $gameReport->duration = 300;
        $gameReport->fps = 60;
        $gameReport->save();

        $pgr1 = new PlayerGameReport();
        $pgr1->game_report_id = $gameReport->id;
        $pgr1->game_id = $game->id;
        $pgr1->player_id = $this->player1->id;
        $pgr1->team = 'A';
        $pgr1->won = 1;
        $pgr1->points = 100;
        $pgr1->save();

        $controller = app(ApiLadderController::class);
        $status = $controller->awardTeamPoints($gameReport, $lh);

        $this->assertEquals(200, $status);
        $pgr1->refresh();
        $this->assertEquals(0, $pgr1->points);
    }

    public function test_casual_clan_game_result_awards_zero_elo(): void
    {
        $lh = $this->makeLadderHistory($this->ladder);
        $this->makePlayerHistory($this->player1, $lh);

        $game = new Game();
        $game->ladder_history_id = $lh->id;
        $game->game_type = Game::GAME_TYPE_2VS2;
        $game->is_casual = true;
        $game->hash = 'test_clan_hash';
        $game->save();

        $gameReport = new GameReport();
        $gameReport->game_id = $game->id;
        $gameReport->valid = true;
        $gameReport->duration = 300;
        $gameReport->fps = 60;
        $gameReport->save();

        $controller = app(ApiLadderController::class);
        $status = $controller->awardClanPoints($gameReport, $lh);

        $this->assertEquals(200, $status);
    }
}
