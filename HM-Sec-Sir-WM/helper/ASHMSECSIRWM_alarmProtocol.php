<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/HM-Sec-Sir-WM
 */

/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection DuplicatedCode */

declare(strict_types=1);

trait ASHMSECSIRWM_alarmProtocol
{
    private function UpdateAlarmProtocol(string $Message): void
    {
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $id = $this->ReadPropertyInteger('AlarmProtocol');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $timestamp = date('d.m.Y, H:i:s');
            $logText = $timestamp . ', ' . $Message;
            $logType = 0;
            $protocol = 'AP_UpdateMessages(' . $id . ', "' . $logText . '", ' . $logType . ');';
            @IPS_RunScriptText($protocol);
            $this->SendDebug(__FUNCTION__, 'Das Alarmprotokoll wurde aktualisiert.', 0);
        }
    }
}