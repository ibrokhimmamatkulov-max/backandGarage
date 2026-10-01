<?php

namespace Tests\Unit;

use App\Services\Sms\TcellSmsGateway;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TcellSmsGatewayTest extends TestCase
{
    private const URL = 'http://tcell.test/sendsms';

    private function gateway(?string $apiKey = 'secret'): TcellSmsGateway
    {
        return new TcellSmsGateway(self::URL, $apiKey, 'Gram', 5);
    }

    public function test_sends_request_in_tcell_format(): void
    {
        Http::fake([self::URL => Http::response(['status' => 'ok'])]);

        $result = $this->gateway()->send('992927990079', 'Код: 1234');

        $this->assertTrue($result->delivered);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::URL
            && $request->hasHeader('X-API-Key', 'secret')
            && $request->isJson()
            && $request->data() === ['from' => 'Gram', 'msisdn' => '992927990079', 'msg' => 'Код: 1234']);
    }

    public function test_error_status_is_failure_without_retry(): void
    {
        Http::fake([self::URL => Http::response('fail', 500)]);

        $result = $this->gateway()->send('992927990079', 'Код: 1234');

        $this->assertFalse($result->delivered);
        $this->assertSame('tcell', $result->driver);
        Http::assertSentCount(1);
    }

    public function test_connection_error_is_failure(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        $result = $this->gateway()->send('992927990079', 'Код: 1234');

        $this->assertFalse($result->delivered);
        $this->assertSame('Шлюз недоступен', $result->error);
    }

    public function test_not_configured_does_not_send(): void
    {
        Http::fake();

        $result = $this->gateway(apiKey: null)->send('992927990079', 'Код: 1234');

        $this->assertFalse($result->delivered);
        Http::assertNothingSent();
    }
}
