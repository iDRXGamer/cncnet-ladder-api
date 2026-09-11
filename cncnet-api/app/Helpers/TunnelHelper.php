<?php

namespace App\Helpers;

class TunnelHelper
{
    public static function getTunnelNameByIpHost($ipHost)
    {
        $tunnels = [
            "United States CnCNet Singapore - rowsnet.com [Official]	v2"                 => "139.180.185.106:50000",
            "United States [FC] EU Tunnel - https://fortuneschaos.com [Official]	v2"     =>    "52.232.96.199:50000",
            "Germany CnCnet Europe - rowsnet.com [Official]	v2"                             => "162.55.221.83:50000",
            "Germany CnCnet Europe - rowsnet.com [Official]	v3"                             => "162.55.221.83:50001",
            "United States CnCNet Singapore - rowsnet.com [Official] v3"                    => "139.180.185.106:50001",
            "Japan CnCNet Japan - rowsnet.com [Official] v2"                                => "45.32.30.135:50000",
            "Japan CnCNet Japan - rowsnet.com [Official] v3"                                => "45.32.30.135:50001",
            "Australia CnCNet Australia - rowsnet.com [Official] v3"                        => "45.63.24.182:50001",
            "Australia CnCNet Australia - rowsnet.com [Official] v2"                        => "45.63.24.182:50000",
            "United States [FC] EU Tunnel - https://fortuneschaos.com [Community] v3"       => "52.232.96.199:50001",
            "United States US - Miami [Community] v3"                                       => "8.6.193.74:50001",
            "Australia WormsRulez [Community] v2"                                           => "202.61.248.165:50000",
            "United States [DE] Clan-Server.EU [3] [Community] v2"                          => "168.119.232.39:50000",
            "Russian Federation [DE] Clan-Server.EU [2] [Community]	v2"                     => "195.201.29.215:50000",
            "United States AUNZ Kippage Gaming [Community] v2"                              => "139.99.178.157:50000",
            "Germany United-Forum.de [Community] v2"                                        => "82.165.113.214:50000",
        ];
        foreach ($tunnels as $name => $ip)
        {
            if ($ip == $ipHost)
            {
                return $name;
            }
        }
        return null;
    }

    public static function getTunnelsFromStats($connectionStats)
    {
        $tunnels = [];
        foreach ($connectionStats as $connectionStat)
        {
            $name = TunnelHelper::getTunnelNameByIpHost($connectionStat->ipAddress->address . ':' . $connectionStat->port);

            if (!in_array($name, $tunnels))
                $tunnels[] = $name;
        }
        
        return $tunnels;
    }

    public static function allocateTunnelPorts(int $playerCount = 2): ?array
    {
        $tunnels = [
            ['ip' => '138.2.138.104', 'port' => 50000, 'name' => 'Frankfurt Relay'],
            ['ip' => '54.36.14.241', 'port' => 50000, 'name' => 'France Kisiek'],
            ['ip' => '23.88.49.17', 'port' => 50000, 'name' => 'Germany Clan-Server'],
            ['ip' => '88.99.76.254', 'port' => 50000, 'name' => 'Fast Server Germany'],
        ];

        foreach ($tunnels as $tunnel)
        {
            try
            {
                $url = "http://{$tunnel['ip']}:{$tunnel['port']}/request?clients={$playerCount}";
                $ctx = stream_context_create([
                    'http' => [
                        'timeout' => 2,
                        'ignore_errors' => true,
                    ]
                ]);

                $response = @file_get_contents($url, false, $ctx);
                if ($response)
                {
                    $raw = trim($response);
                    $raw = str_replace(['[', ']'], '', $raw);
                    $parts = explode(',', $raw);
                    $ports = [];

                    foreach ($parts as $part)
                    {
                        $p = (int)trim($part);
                        if ($p < 0)
                        {
                            $p += 65536;
                        }
                        if ($p > 0 && $p <= 65535)
                        {
                            $ports[] = $p;
                        }
                    }

                    if (count($ports) >= $playerCount)
                    {
                        \Illuminate\Support\Facades\Log::info("[TunnelHelper] Allocated {$playerCount} ports from {$tunnel['name']} ({$tunnel['ip']}:{$tunnel['port']}): " . implode(', ', $ports));
                        return [
                            'ip' => $tunnel['ip'],
                            'port' => $tunnel['port'],
                            'name' => $tunnel['name'],
                            'ports' => $ports,
                        ];
                    }
                }
            }
            catch (\Throwable $e)
            {
                \Illuminate\Support\Facades\Log::warning("[TunnelHelper] Failed to allocate tunnel ports from {$tunnel['ip']}: " . $e->getMessage());
            }
        }

        \Illuminate\Support\Facades\Log::warning("[TunnelHelper] All tunnel allocations failed for {$playerCount} clients.");
        return null;
    }
}
