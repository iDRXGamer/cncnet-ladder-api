<?php

namespace App\Http\Services;

use App\Models\QmMatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Relays casual matches through a CnCNet V2 tunnel. Casual clients do not negotiate a
 * tunnel themselves, so the server requests one port per player when a match is created.
 */
class CasualTunnelService
{
    private const CACHE_SECONDS = 3600;
    private const REQUEST_TIMEOUT_SECONDS = 2;

    /**
     * Requests tunnel ports for the players of a match and assigns them to the players.
     * When no tunnel is available the players connect to each other directly.
     */
    public function assignTunnel(QmMatch $qmMatch): void
    {
        $players = $qmMatch->players()->orderBy('color')->get()->filter(fn($player) => !$player->isObserver())->values();

        $tunnel = $this->allocatePorts($players->count(), config('qm.casual_tunnels', []));
        if ($tunnel === null)
        {
            Log::warning("[CasualTunnelService] No tunnel available for match {$qmMatch->id}, players connect directly.");
            return;
        }

        Cache::put(self::cacheKey($qmMatch->id), ['ip' => $tunnel['ip'], 'port' => $tunnel['port']], self::CACHE_SECONDS);

        foreach ($players as $index => $player)
        {
            $player->port = $tunnel['ports'][$index];
            $player->save();
        }
    }

    /**
     * Gets the tunnel assigned to a match, or null if the players connect directly.
     * @return array|null ['ip' => string, 'port' => int]
     */
    public function getTunnel(int $qmMatchId): ?array
    {
        return Cache::get(self::cacheKey($qmMatchId));
    }

    /**
     * Requests ports from the first tunnel that has enough free slots.
     * @param array $tunnels list of ['ip' => string, 'port' => int, 'name' => string]
     * @return array|null ['ip', 'port', 'name', 'ports' => int[]]
     */
    public function allocatePorts(int $playerCount, array $tunnels): ?array
    {
        foreach ($tunnels as $tunnel)
        {
            try
            {
                $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                    ->get("http://{$tunnel['ip']}:{$tunnel['port']}/request", ['clients' => $playerCount]);

                if (!$response->successful())
                    continue;

                $ports = self::parsePorts($response->body());
                if (count($ports) >= $playerCount)
                {
                    Log::info("[CasualTunnelService] Allocated {$playerCount} ports from {$tunnel['name']} ({$tunnel['ip']}:{$tunnel['port']})");

                    return $tunnel + ['ports' => $ports];
                }
            }
            catch (\Throwable $e)
            {
                Log::warning("[CasualTunnelService] Failed to allocate ports from {$tunnel['ip']}: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Parses a tunnel response such as "[1234,-5678]". Ports above 32767 are sent as negative numbers.
     * @return int[]
     */
    private static function parsePorts(string $body): array
    {
        $ports = [];
        foreach (explode(',', str_replace(['[', ']'], '', trim($body))) as $part)
        {
            $port = (int)trim($part);
            if ($port < 0)
            {
                $port += 65536;
            }

            if ($port > 0 && $port <= 65535)
            {
                $ports[] = $port;
            }
        }

        return $ports;
    }

    private static function cacheKey(int $qmMatchId): string
    {
        return "qm_casual_match_tunnel:{$qmMatchId}";
    }
}
