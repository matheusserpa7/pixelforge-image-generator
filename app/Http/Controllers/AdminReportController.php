<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\BuildAdminReport;
use App\Data\AdminReport;
use App\Data\AdminReportDay;
use App\Data\AdminReportStuckGeneration;
use App\Data\AdminReportUser;
use App\Http\Requests\AdminReportRequest;
use App\Http\Resources\MoneyView;
use App\Support\Money\Money;
use Inertia\Inertia;
use Inertia\Response;

final class AdminReportController extends Controller
{
    public function show(AdminReportRequest $request, BuildAdminReport $buildReport): Response
    {
        $report = $buildReport->handle($request->start(), $request->end());

        return Inertia::render('admin/Report', $this->props($report));
    }

    /**
     * @return array<string, mixed>
     */
    private function props(AdminReport $report): array
    {
        $totals = $report->totals;

        return [
            'range' => [
                'start' => $report->start->format('Y-m-d'),
                'end' => $report->end->format('Y-m-d'),
            ],
            'providerUsageAvailable' => $report->providerUsageAvailable,
            'totals' => [
                'generations' => $totals->generations,
                'charged' => $this->money($totals->charged),
                'providerCost' => $this->money($totals->providerCost),
                'profit' => $this->money($totals->profit),
                'pixelforgeCost' => $this->nullableMoney($totals->pixelforgeCost),
                'matches' => $totals->matches,
            ],
            'days' => array_map(fn (AdminReportDay $day): array => [
                'date' => $day->date,
                'generations' => $day->generations,
                'charged' => $this->money($day->charged),
                'providerCost' => $this->money($day->providerCost),
                'profit' => $this->money($day->profit),
                'pixelforgeCost' => $this->nullableMoney($day->pixelforgeCost),
                'difference' => $this->nullableMoney($day->difference),
                'matches' => $day->matches,
            ], $report->days),
            'users' => array_map(fn (AdminReportUser $user): array => [
                'userId' => $user->userId,
                'name' => $user->name,
                'email' => $user->email,
                'generations' => $user->generations,
                'charged' => $this->money($user->charged),
                'providerCost' => $this->money($user->providerCost),
                'profit' => $this->money($user->profit),
            ], $report->users),
            'stuck' => array_map(fn (AdminReportStuckGeneration $row): array => [
                'id' => $row->id,
                'userEmail' => $row->userEmail,
                'status' => $row->status,
                'providerId' => $row->providerId,
                'createdAt' => $row->createdAt->utc()->format('Y-m-d H:i'),
            ], $report->stuck),
        ];
    }

    /**
     * @return array{amount: string, display: string}
     */
    private function money(Money $money): array
    {
        return MoneyView::of($money->nanos);
    }

    /**
     * @return array{amount: string, display: string}|null
     */
    private function nullableMoney(?Money $money): ?array
    {
        return $money === null ? null : $this->money($money);
    }
}
