<?php

namespace Tests\Unit;

use App\Models\Mill;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class DashboardThemePresentationTest extends TestCase
{
    public function test_dashboard_renders_all_periods_and_mill_filters_with_shared_theme(): void
    {
        $user = new User(['name' => 'UI Test', 'is_active' => true]);
        $user->setRelation('role', new Role(['name' => Role::ADMIN, 'label' => 'Admin']));
        $user->setRelation('mill', null);
        $this->actingAs($user);
        view()->share('errors', new ViewErrorBag());

        $mills = collect([
            new Mill(['name' => 'Kahang', 'code' => 'KHG']),
            new Mill(['name' => 'Bukit Bujang', 'code' => 'BBJ']),
        ]);
        $mills[0]->id = 1;
        $mills[1]->id = 2;

        foreach (['harian', 'mtd', 'ytd'] as $period) {
            foreach ([null, 1, 2] as $millId) {
                $html = view('dashboard.index', [
                    'mills' => $mills,
                    'selectedMillId' => $millId,
                    'selectedPeriod' => $period,
                    'displayDateCarbon' => Carbon::parse('2026-10-28'),
                    'latestOperationDateText' => '28 October 2026',
                    'mpobPrices' => ['products' => [], 'source_available' => false],
                    'mpobTrendData' => [],
                    'operationalStatusByMill' => collect(),
                    'showYtdBaselineNote' => true,
                    'periodMetrics' => [],
                    'kpiStatuses' => [],
                    'trendHasData' => false,
                    'comparisonMetrics' => ['by_mill' => ['KHG' => null, 'BBJ' => null], 'total' => []],
                    'millsBelumHantar' => collect(),
                    'qualityPendingCount' => 0,
                    'alertMessages' => [],
                    'labels' => [],
                    'btsProcessedTrend' => [],
                    'oerTrend' => [],
                    'kerTrend' => [],
                ])->render();

                $this->assertStringContainsString('id="mps-theme-toggle"', $html);
                $this->assertSame(2, substr_count($html, 'class="mps-sidebar '));
                $this->assertStringContainsString('class="mps-main p-6"', $html);
                $this->assertStringNotContainsString('class="mps-app bg-gray-50', $html);
                $this->assertStringContainsString('name="tarikh" value="2026-10-28"', $html);
                $this->assertStringContainsString('dashboard/weather', $html);
                $this->assertStringNotContainsString('palm-ornament', $html);
                $this->assertStringNotContainsString('YTD 2026 menggabungkan sejarah bulanan Januari-Jun', $html);
                $this->assertSame(9, substr_count($html, '<article class="mps-card kpi-card">'));
                $this->assertLessThan(strpos($html, 'cdn.tailwindcss.com'), strpos($html, 'js/mps-theme.js'));

                $headings = ['Status Stok Kilang', 'Harga Semasa MPOB', 'Prestasi Operasi Kilang', 'Trend Prestasi Bulanan'];
                for ($index = 1; $index < count($headings); $index++) {
                    $this->assertLessThan(
                        strpos($html, $headings[$index]),
                        strpos($html, $headings[$index - 1])
                    );
                }
            }
        }
    }
}
