<?php

declare(strict_types=1);

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\Exceptions\PixelforgeRequestFailed;
use App\Pixelforge\Exceptions\PixelforgeUnreachable;
use App\Pixelforge\UsageRepository;
use App\Support\Usd;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class ProfitReport
{
    public function __construct(private UsageRepository $usage) {}

    /** @return array<string, mixed> */
    public function build(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $start = $start->setTimezone('UTC')->startOfDay();
        $end = $end->setTimezone('UTC')->startOfDay();
        // One aggregate query keeps daily and user figures on the same snapshot.
        /** @var list<object{date: string, user_id: int, name: string, email: string, generations: int|string, billed: int|string, cost: int|string}> $rows */
        $rows = DB::table('generations')
            ->join('users', 'users.id', '=', 'generations.user_id')
            ->where('status', GenerationStatus::Succeeded->value)
            ->where('completed_at', '>=', $start->toIso8601String())
            ->where('completed_at', '<', $end->addDay()->toIso8601String())
            ->selectRaw("(completed_at AT TIME ZONE 'UTC')::date AS date, users.id AS user_id, users.name, users.email, COUNT(*) AS generations, SUM(price_nanodollars) AS billed, SUM(provider_cost_nanodollars) AS cost")
            ->groupByRaw("(completed_at AT TIME ZONE 'UTC')::date, users.id, users.name, users.email")
            ->orderBy('users.name')->orderBy('users.id')->orderBy('date')
            ->get()->all();

        $daily = [];
        $users = [];
        $totalCount = $totalBilled = $totalCost = 0;
        foreach ($rows as $row) {
            $count = (int) $row->generations;
            $billed = (int) $row->billed;
            $cost = (int) $row->cost;
            $day = $daily[$row->date] ?? ['generations' => 0, 'billed' => 0, 'cost' => 0];
            $daily[$row->date] = ['generations' => $day['generations'] + $count, 'billed' => $day['billed'] + $billed, 'cost' => $day['cost'] + $cost];
            $user = $users[$row->user_id] ?? ['id' => $row->user_id, 'name' => $row->name, 'email' => $row->email, 'generations' => 0, 'billed' => 0, 'cost' => 0];
            $user['generations'] += $count;
            $user['billed'] += $billed;
            $user['cost'] += $cost;
            $users[$row->user_id] = $user;
            $totalCount += $count;
            $totalBilled += $billed;
            $totalCost += $cost;
        }

        $provider = null;
        $message = null;
        try {
            $provider = $this->usage->forRange($start->toDateString(), $end->toDateString());
        } catch (PixelforgeUnreachable|PixelforgeRequestFailed|InvalidPixelforgePayload $exception) {
            $message = 'Pixelforge usage is unavailable right now; showing our records only.';
            Log::warning($message, ['exception' => $exception]);
        }
        $providerDays = [];
        foreach ($provider->days ?? [] as $day) {
            $providerDays[$day->date] = $day;
        }

        $days = [];
        $matching = 0;
        for ($date = $start; $date <= $end; $date = $date->addDay()) {
            $key = $date->toDateString();
            $ours = $daily[$key] ?? ['generations' => 0, 'billed' => 0, 'cost' => 0];
            $comparison = null;
            if (isset($providerDays[$key])) {
                $theirs = $providerDays[$key];
                $difference = $ours['cost'] - $theirs->costNanodollars;
                $match = $ours['generations'] === $theirs->generations && $difference === 0;
                $matching += (int) $match;
                $comparison = [
                    'generations' => $theirs->generations,
                    'cost_nanodollars' => $theirs->costNanodollars,
                    'cost' => Usd::exact($theirs->costNanodollars),
                    'match' => $match,
                    'difference_nanodollars' => $difference,
                    'difference' => Usd::exact($difference),
                ];
            }
            $days[] = ['date' => $key, ...self::amounts($ours['generations'], $ours['billed'], $ours['cost']), 'pixelforge' => $comparison];
        }

        return [
            'days' => $days,
            'users' => array_values(array_map(fn (array $user) => [
                'id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'],
                ...self::amounts($user['generations'], $user['billed'], $user['cost']),
            ], $users)),
            'totals' => self::amounts($totalCount, $totalBilled, $totalCost),
            'pixelforge' => $provider === null ? null : ['matching_days' => $matching, 'total_days' => count($days)],
            'message' => $message,
        ];
    }

    /** @return array{generations: int, billed_nanodollars: int, cost_nanodollars: int, margin_nanodollars: int, billed: string, cost: string, margin: string} */
    private static function amounts(int $count, int $billed, int $cost): array
    {
        return [
            'generations' => $count,
            'billed_nanodollars' => $billed,
            'cost_nanodollars' => $cost,
            'margin_nanodollars' => $billed - $cost,
            'billed' => Usd::display($billed),
            'cost' => Usd::exact($cost),
            'margin' => Usd::exact($billed - $cost),
        ];
    }
}
