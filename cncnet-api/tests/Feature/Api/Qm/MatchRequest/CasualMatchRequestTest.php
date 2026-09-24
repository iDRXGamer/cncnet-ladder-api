<?php

namespace Tests\Feature\Api\Qm\MatchRequest;

use App\Models\Player;
use App\Models\QmMatch;
use App\Models\QmQueueEntry;
use App\Models\QmUserId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Auth\JwtAuthHelper;
use Tests\TestCase;

class CasualMatchRequestTest extends TestCase
{
    use RefreshDatabase;
    use QmPlayerHelper;
    use JwtAuthHelper;

    private $ladder;
    private $rankedLadder;
    private Carbon $now;

    private array $matchRequest = [
        'version' => '1.83',
        'type' => 'match me up',
        'side' => 1,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Ladder histories are created for the month of the current (test) time
        $this->now = Carbon::create(2026, 4, 20, 10, 10, 0);
        Carbon::setTestNow($this->now);

        $this->ladder = $this->makeLadder(2);
        $this->ladder->is_casual = true;
        $this->ladder->save();
        $this->makeLadderHistory($this->ladder);

        // makeLadder() always uses the abbreviation "tl"
        $this->rankedLadder = $this->makeLadder(2);
        $this->rankedLadder->abbreviation = 'tl-ranked';
        $this->rankedLadder->save();
        $this->makeLadderHistory($this->rankedLadder);

        // Tests must not contact the real tunnel servers; by default no tunnel is configured
        config(['qm.casual_tunnels' => []]);
        Http::preventStrayRequests();
    }

    private function casualRequest(string $playerName, array $data = [])
    {
        return $this->postJson('/api/v1/qm/casual/' . $this->ladder->abbreviation . '/' . rawurlencode($playerName), $data + $this->matchRequest);
    }

    private function waitSeconds(int $seconds): void
    {
        $this->now = $this->now->clone()->addSeconds($seconds);
        Carbon::setTestNow($this->now);
    }

    public function test_players_are_matched_without_accounts(): void
    {
        $first = $this->casualRequest('Newcomer1');
        $this->assertEquals('please wait', $first->json('type'), json_encode($first->json()));
        $this->assertEquals(5, $first->json('checkback'));

        $this->waitSeconds(5);
        $second = $this->casualRequest('Newcomer2');

        $this->assertEquals('spawn', $second->json('type'), json_encode($second->json()));
        $this->assertEquals(2, $second->json('spawn.Settings.PlayerCount'));
        $this->assertNotNull($second->json('spawn.HouseCountries'));
        $this->assertEquals('Newcomer1', $second->json('spawn.Other1.Name'));
        $this->assertStringEndsWith('@casual.invalid', Player::where('username', 'Newcomer1')->first()->user->email);
    }

    public function test_first_player_receives_spawn_on_next_checkback(): void
    {
        $this->casualRequest('Newcomer1');
        $this->waitSeconds(5);
        $this->casualRequest('Newcomer2');

        $this->waitSeconds(5);
        $response = $this->casualRequest('Newcomer1');

        $this->assertEquals('spawn', $response->json('type'), json_encode($response->json()));
        $this->assertEquals('Newcomer2', $response->json('spawn.Other1.Name'));
    }

    public function test_match_is_relayed_through_allocated_tunnel(): void
    {
        config(['qm.casual_tunnels' => [['ip' => '203.0.113.10', 'port' => 50000, 'name' => 'Test Tunnel']]]);
        Http::fake(['203.0.113.10:50000/*' => Http::response('[1234,-5678]', 200)]);

        $this->casualRequest('Newcomer1');
        $this->waitSeconds(5);
        $response = $this->casualRequest('Newcomer2');

        $this->assertEquals('spawn', $response->json('type'), json_encode($response->json()));
        $this->assertEquals('203.0.113.10', $response->json('spawn.Tunnel.Ip'));
        $this->assertEquals(50000, $response->json('spawn.Tunnel.Port'));
        $this->assertEquals('0.0.0.0', $response->json('spawn.Other1.Ip'));
        $this->assertContains($response->json('spawn.Settings.Port'), [1234, 59858]);
    }

    public function test_players_that_stopped_polling_are_not_matched(): void
    {
        $this->casualRequest('Newcomer1');

        $this->waitSeconds(15);
        $response = $this->casualRequest('Newcomer2');

        $this->assertEquals('please wait', $response->json('type'));
        $this->assertEquals(0, QmMatch::count());
    }

    public function test_quit_removes_the_player_from_the_queue(): void
    {
        $this->casualRequest('Newcomer1');
        $this->assertEquals(1, QmQueueEntry::count());

        $response = $this->casualRequest('Newcomer1', ['type' => 'quit']);

        $this->assertEquals('quit', $response->json('type'));
        $this->assertEquals(0, QmQueueEntry::count());
    }

    public function test_queue_counts_only_include_casual_ladders(): void
    {
        $this->casualRequest('Newcomer1');

        $counts = $this->getJson('/api/v1/qm/casual/queue-counts')->json();

        $this->assertEquals([$this->ladder->abbreviation => 1], $counts);
    }

    public function test_casual_request_is_rejected_on_ranked_ladder(): void
    {
        $response = $this->postJson('/api/v1/qm/casual/' . $this->rankedLadder->abbreviation . '/Newcomer1', $this->matchRequest);

        $this->assertEquals('fatal', $response->json('type'));
        $this->assertEquals(0, Player::where('username', 'Newcomer1')->count());
    }

    public function test_ranked_request_is_rejected_on_casual_ladder(): void
    {
        $user = $this->makeUser('ranked_user');
        $player = $this->makePlayerForLadder('ranked_player', $this->ladder, $user);

        $response = $this
            ->jwtAuth($user)
            ->postJson('/api/v1/qm/' . $this->ladder->abbreviation . '/' . $player->username, $this->matchRequest + ['map_sides' => [1, 1, 1, 1]]);

        $this->assertEquals('fatal', $response->json('type'), json_encode($response->json()));
        $this->assertEquals(0, QmQueueEntry::count());
    }

    public function test_ranked_route_requires_authentication_even_with_casual_flag(): void
    {
        $response = $this->postJson('/api/v1/qm/' . $this->rankedLadder->abbreviation . '/Newcomer1', [
            'version' => '1.83',
            'type' => 'quit',
            'casual' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_registered_player_on_casual_ladder_cannot_be_taken_over(): void
    {
        $user = $this->makeUser('registered');
        $player = $this->makePlayerForLadder('registered', $this->ladder, $user);

        $response = $this->casualRequest($player->username, ['hwid' => 'other-hwid']);

        $this->assertEquals('fatal', $response->json('type'));
        $this->assertEquals(0, QmQueueEntry::count());
        $this->assertEquals(0, QmUserId::where('user_id', $user->id)->count());
    }

    public function test_name_of_registered_account_gets_separate_casual_account(): void
    {
        $registeredUser = $this->makeUser('RegUser');

        $response = $this->casualRequest('RegUser', ['hwid' => 'casual-hwid']);

        $this->assertEquals('please wait', $response->json('type'), json_encode($response->json()));

        $player = Player::where('username', 'RegUser')->first();
        $this->assertNotEquals($registeredUser->id, $player->user_id);
        $this->assertEquals(0, QmUserId::where('user_id', $registeredUser->id)->count());
    }

    public function test_accepts_any_valid_cncnet_nickname(): void
    {
        foreach (['A', 'Long_Nickname123', '[Clan]Pl\\yer', '{x}^`|'] as $name)
        {
            $response = $this->casualRequest($name);

            // Players queue up ("please wait") or are matched with the previous name ("spawn")
            $this->assertContains($response->json('type'), ['please wait', 'spawn'], $name . ': ' . json_encode($response->json()));
        }
    }

    public function test_rejects_invalid_player_names(): void
    {
        foreach (["Bad\nName", '1Player', '-Player', 'NameThatIsTooLong', 'Bad Name'] as $name)
        {
            $this->assertEquals('fatal', $this->casualRequest($name)->json('type'), $name);
        }

        $this->assertEquals(0, Player::count());
    }
}
