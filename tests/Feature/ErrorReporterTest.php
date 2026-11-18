<?php

use Consys\Ecosystem\Monitor\ErrorReport;
use Consys\Ecosystem\Monitor\SendErrorReport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Http::fake(['admin.test/*' => Http::response(['received' => true])]);
});

it('sends the exceptions the app reports to the admin', function (): void {
    report(new RuntimeException('Boletim not found'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://admin.test/api/v1/monitor/errors'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer directory-secret')
        && $request['application'] === 'erp'
        && $request['exception'] === RuntimeException::class
        && $request['message'] === 'Boletim not found'
        && str_ends_with($request['file'], 'tests/Feature/ErrorReporterTest.php')
        && strlen($request['fingerprint']) === 40
        && $request['url'] === null);
});

it('sends the report through the queue', function (): void {
    Queue::fake();

    report(new RuntimeException('Queued'));

    Queue::assertPushed(SendErrorReport::class, fn (SendErrorReport $job): bool => $job->report['message'] === 'Queued');
    Http::assertNothingSent();
});

it('records the request an exception happened in, without the query string', function (): void {
    Route::get('/boletins/{id}', fn () => throw new LogicException('Broken boletim'));

    $this->get('/boletins/7?token=abc')->assertServerError();

    Http::assertSent(fn (Request $request): bool => $request['url'] === 'http://localhost/boletins/7'
        && $request['method'] === 'GET'
        && $request['command'] === null
        && $request['exception'] === LogicException::class);
});

it('leaves out the exceptions laravel does not report', function (): void {
    Route::get('/missing', fn () => abort(404));

    $this->get('/missing')->assertNotFound();

    Http::assertNothingSent();
});

it('groups the same failure under one fingerprint and keeps others apart', function (): void {
    $first = ErrorReport::fromThrowable(failAt('same place'));
    $second = ErrorReport::fromThrowable(failAt('another message'));
    $other = ErrorReport::fromThrowable(new RuntimeException('elsewhere'));

    expect($first->fingerprint)->toBe($second->fingerprint)
        ->and($other->fingerprint)->not->toBe($first->fingerprint);
});

it('points a vendor exception at the line of the app that called the vendor code', function (): void {
    $exception = null;

    try {
        collect([1])->sole(fn (): bool => false);
    } catch (Throwable $caught) {
        $exception = $caught;
    }

    $report = ErrorReport::fromThrowable($exception);

    expect($report->file)->toEndWith('tests/Feature/ErrorReporterTest.php')
        ->and($report->trace[0]['file'])->toContain('/vendor/laravel/framework/');
});

it('filters secrets out of the message', function (): void {
    $report = ErrorReport::fromThrowable(new RuntimeException('Login failed password=hunter2 token: "abc123" for diego'));

    expect($report->message)->toBe('Login failed password=[filtered] token: "[filtered]" for diego');
});

it('stops sending the same error after the limit per minute', function (): void {
    config(['consys.monitor.errors_per_minute' => 3]);

    foreach (range(1, 5) as $attempt) {
        report(failAt('again'));
    }

    Http::assertSentCount(3);
});

it('does nothing while the admin is not configured or the reports are off', function (array $config): void {
    config($config);

    report(new RuntimeException('Not sent'));

    Http::assertNothingSent();
})->with([
    'no admin url' => [['consys.directory.admin_url' => null]],
    'no secret' => [['consys.directory.secret' => '']],
    'reports off' => [['consys.monitor.report_errors' => false]],
]);

it('never throws when the admin cannot be reached', function (): void {
    Http::fake(['admin.test/*' => unreachable()]);

    report(new RuntimeException('Admin down'));

    expect((new SendErrorReport(['message' => 'x']))->deliver())->toBeFalse();
});

function failAt(string $message): RuntimeException
{
    return new RuntimeException($message);
}

it('sends what an exception tells about itself, without its secrets', function (): void {
    $exception = new class('Refused') extends RuntimeException
    {
        public function context(): array
        {
            return ['phone' => '5511999990000', 'instance' => 7, 'api_token' => 'abc', 'payload' => ['a' => 1]];
        }
    };

    expect(ErrorReport::fromThrowable($exception)->toArray()['context'])->toBe([
        'phone' => '5511999990000',
        'instance' => 7,
        'api_token' => '[filtered]',
        'payload' => '{"a":1}',
    ]);
});
