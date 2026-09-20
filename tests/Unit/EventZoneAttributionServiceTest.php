<?php

namespace Tests\Unit;

use App\Services\EventZoneAttributionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EventZoneAttributionServiceTest extends TestCase
{
    #[DataProvider('storeNames')]
    public function test_automatic_zones_follow_the_tpa_name_pattern(string $storeName, string $expected): void
    {
        $this->assertSame($expected, (new EventZoneAttributionService)->fallbackLabel($storeName));
    }

    /** @return array<string, array{string, string}> */
    public static function storeNames(): array
    {
        return [
            'redbull' => ['Bar REDBULL - Raquel C - POS 1', 'Bar REDBULL'],
            'somersby' => ['Bar Somersby - Lara A - POS 1', 'Bar Somersby'],
            'vip ciroc' => ['Bar VIP Ciroc - Tiago S - POS 1', 'Bar Vip'],
            'vip palco' => ['Bar VIP Palco - Sonia T - POS 1', 'Bar Vip'],
            'vinho e jw' => ['Bar Vinho / JW - Hugo - POS 1', 'Bar Vinho / JW'],
            'top up' => ['Top UP - Left - POS 1', 'Top Up'],
            'estacionamento' => ['Estacionamento - Park 1 - POS 1', 'Estacionamento'],
            'privados' => ['Privados 2 - POS 1', 'Privados'],
            'privados device' => ['Privados - Device 2 - POS 1', 'Privados'],
            'restauracao street' => ['Restauração - Street 2 - POS 1', 'Restauração'],
            'restauracao individual' => ['Restauração - Cookie - POS 1', 'Restauração'],
            'torto' => ['Torto - João Salgado - POS 1', 'Torto'],
            'legacy vip' => ['Tpa 8 - Bar Vip Alison - POS 1', 'Bar Vip'],
            'legacy numbered bar' => ['Tpa 7 - Bar 3 Ana - POS 1', 'Bar 3'],
            'company name is not a store' => ['Pausas Animadas - Lda - POS 1', 'Sem zona'],
        ];
    }
}
