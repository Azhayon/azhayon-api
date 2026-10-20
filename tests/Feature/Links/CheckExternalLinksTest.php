<?php

namespace Tests\Feature\Links;

use App\Models\LinkCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckExternalLinksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['azhayon.link_checker.delay_ms' => 0]);
    }

    private function check(string $url): LinkCheck
    {
        $check = (new LinkCheck)->forceFill([
            'url_hash' => LinkCheck::hash($url),
            'url' => $url,
            'status' => LinkCheck::PENDING,
            'next_check_at' => null,
        ]);
        $check->save();

        return $check;
    }

    private function row(string $url): LinkCheck
    {
        return LinkCheck::where('url_hash', LinkCheck::hash($url))->firstOrFail();
    }

    public function test_results_are_recorded_and_a_failure_must_repeat_before_a_link_is_broken(): void
    {
        $good = 'https://93.184.216.34/good';
        $gone = 'https://93.184.216.35/gone';
        $old = 'https://93.184.216.36/old';
        $private = 'https://93.184.216.37/private';

        foreach ([$good, $gone, $old, $private] as $url) {
            $this->check($url);
        }

        $responses = [
            $good => [200, []],
            $gone => [404, []],
            $old => [301, ['Location' => '/new']],
            'https://93.184.216.36/new' => [200, []],
            $private => [403, []],
        ];

        Http::fake(function (Request $request) use (&$responses) {
            [$status, $headers] = $responses[$request->url()] ?? [200, []];

            return Http::response('', $status, $headers);
        });

        // First run.
        $this->artisan('links:check-external')->assertSuccessful();

        $row = $this->row($good);
        $this->assertSame('ok', $row->status);
        $this->assertSame(0, $row->failures);
        $this->assertEqualsWithDelta(now()->addDays(14)->timestamp, $row->next_check_at->timestamp, 5);

        $row = $this->row($gone);
        $this->assertSame('failing', $row->status); // not reported yet
        $this->assertSame(1, $row->failures);
        $this->assertSame(404, $row->http_status);
        $this->assertEqualsWithDelta(now()->addDay()->timestamp, $row->next_check_at->timestamp, 5);

        $row = $this->row($old);
        $this->assertSame('moved', $row->status);
        $this->assertSame('https://93.184.216.36/new', $row->final_url);

        $row = $this->row($private);
        $this->assertSame('unverifiable', $row->status);
        $this->assertSame('blocked-by-site', $row->note);
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, $row->next_check_at->timestamp, 5);

        // Nothing is due again straight away.
        $checkedAt = $this->row($good)->checked_at;
        $this->artisan('links:check-external')->assertSuccessful();
        $this->assertEquals($checkedAt, $this->row($good)->checked_at);

        // Two days later the failing link fails again and is now confirmed broken.
        $this->travel(2)->days();
        $this->artisan('links:check-external')->assertSuccessful();

        $row = $this->row($gone);
        $this->assertSame('broken', $row->status);
        $this->assertSame(2, $row->failures);

        // It comes back to life.
        $responses[$gone] = [200, []];
        $this->travel(4)->days();
        $this->artisan('links:check-external')->assertSuccessful();

        $row = $this->row($gone);
        $this->assertSame('ok', $row->status);
        $this->assertSame(0, $row->failures);
        $this->assertNull($row->http_status === 200 ? null : $row->http_status);
    }

    public function test_one_website_is_never_hit_more_than_three_times_per_run(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        foreach (range(1, 5) as $page) {
            $this->check("https://93.184.216.34/page-{$page}");
        }

        $this->artisan('links:check-external')->assertSuccessful();

        $this->assertSame(3, LinkCheck::whereNotNull('checked_at')->count());
    }

    public function test_the_limit_option_caps_a_run(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        foreach (['93.184.216.34', '93.184.216.35', '93.184.216.36', '8.8.8.8', '1.1.1.1'] as $host) {
            $this->check("https://{$host}/");
        }

        $this->artisan('links:check-external', ['--limit' => 2])->assertSuccessful();

        $this->assertSame(2, LinkCheck::whereNotNull('checked_at')->count());
    }
}