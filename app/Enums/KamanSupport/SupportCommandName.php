<?php

declare(strict_types=1);

namespace App\Enums\KamanSupport;

enum SupportCommandName: string
{
    case PingPrinter = 'ping_printer';
    case ScanLan = 'scan_lan';
    case MapLan = 'map_lan';
    case ListPrinters = 'list_printers';
    case RemapPrinter = 'remap_printer';
    case RetryFailedPrints = 'retry_failed_prints';
    case InspectOrder = 'inspect_order';
    case TestPrint = 'test_print';
    case SetRole = 'set_role';
    case SetKitchenType = 'set_kitchen_type';
    case RememberPrinter = 'remember_printer';
    case ForgetPrinter = 'forget_printer';
    case SyncKitchenApi = 'sync_kitchen_api';
    case PressureTest = 'pressure_test';
    case PressureStop = 'pressure_stop';
    case PressureClear = 'pressure_clear';
    case ApplySettings = 'apply_settings';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $command): string => $command->value, self::cases());
    }

    public function label(): string
    {
        return __('kaman-support.command.'.$this->value);
    }
}
