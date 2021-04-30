<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license     CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/HM-Sec-Sir-WM
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait ASHMSECSIRWM_alarmSiren
{
    public function ResetSignallingAmount(): void
    {
        $this->SetValue('SignallingAmount', 0);
        $this->SendDebug(__FUNCTION__, 'Die Anzahl der Auslösungen wurde zurückgesetzt.', 0);
    }

    public function ToggleAlarmSiren(bool $State): bool
    {
        $id = $this->ReadPropertyInteger('AlarmSiren');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            return false;
        }
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $statusValue = $this->GetValue('Status');
        $signallingAmountValue = $this->GetValue('SignallingAmount');
        // Switch alarm siren off
        if (!$State) {
            // Deactivate all timers
            $this->SetTimerInterval('DeactivatePreAlarm', 0);
            $this->SetTimerInterval('ExecuteMainAlarm', 0);
            $this->SetTimerInterval('DeactivateAlarmSiren', 0);
            $this->SetValue('AlarmSiren', false);
            $this->SetValue('Status', 0);
            $result = $this->SwitchAlarmSiren(false);
            if (!$result) {
                // Revert on failure
                $this->SetValue('AlarmSiren', $alarmSirenValue);
                $this->SetValue('Status', $statusValue);
                $this->SetValue('SignallingAmount', $signallingAmountValue);
                $text = 'Fehler, die Alarmsirene konnte nicht ausgeschaltet werden!';
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            } else {
                $text = 'Die Alarmsirene wurde ausgeschaltet.';
                if ($State != $alarmSirenValue) {
                    $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
                }
            }
        } // Switch alarm siren on
        else {
            if ($this->CheckMaintenanceMode()) {
                return false;
            }
            if ($this->CheckMuteMode()) {
                return false;
            }
            if (!$this->CheckSignallingAmount()) {
                return false;
            }
            if ($this->GetValue('Status') != 0) {
                return false;
            }
            if ($id == 0 || @!IPS_ObjectExists($id)) {
                return false;
            }
            $result = false;
            // Check pre alarm
            $usePreAlarm = $this->ReadPropertyBoolean('UsePreAlarm');
            if ($usePreAlarm) {
                $result = $this->ExecutePreAlarm();
            }
            // Check main alarm
            $useMainAlarm = $this->ReadPropertyBoolean('UseMainAlarm');
            if (!$usePreAlarm && $useMainAlarm) {
                $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
                if ($delay == 0) {
                    $result = $this->ExecuteMainAlarm(false);
                } else {
                    $this->SetTimerInterval('ExecuteMainAlarm', $delay * 1000);
                    $unit = 'Sekunden';
                    if ($delay == 1) {
                        $unit = 'Sekunde';
                    }
                    $text = 'Die Alarmsirene wird in ' . $delay . ' ' . $unit . ' eingeschaltet.';
                }
            }
        }
        if (isset($text)) {
            $this->SendDebug(__FUNCTION__, $text, 0);
        }
        return $result;
    }

    public function ExecutePreAlarm(): bool
    {
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckMuteMode()) {
            return false;
        }
        if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
            return false;
        }
        if (!$this->CheckSignallingAmount()) {
            return false;
        }
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $statusValue = $this->GetValue('Status');
        // Check main alarm
        if ($alarmSirenValue && $statusValue == 2) {
            return false;
        }
        $this->SetValue('AlarmSiren', true);
        $this->SetValue('Status', 1);
        $result = $this->SwitchAlarmSiren(true);
        if (!$result) {
            // Revert on failure
            $this->SetValue('AlarmSiren', $alarmSirenValue);
            $this->SetValue('Status', $statusValue);
            $text = 'Fehler, der Voralarm konnte nicht eingeschaltet werden!';
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Der Voralarm wurde eingeschaltet.';
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        $preAlarmDuration = $this->ReadPropertyInteger('PreAlarmDuration');
        $this->SetTimerInterval('DeactivatePreAlarm', $preAlarmDuration * 1000);
        $unit = 'Sekunden';
        if ($preAlarmDuration == 1) {
            $unit = 'Sekunde';
        }
        $this->SendDebug(__FUNCTION__, 'Der Voralarm wird in ' . $preAlarmDuration . ' ' . $unit . ' ausgeschaltet.', 0);
        // Main alarm
        if ($this->ReadPropertyBoolean('UseMainAlarm')) {
            $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($delay > $preAlarmDuration) {
                $unit = 'Sekunden';
                if ($delay == 1) {
                    $unit = 'Sekunde';
                }
                $this->SetTimerInterval('ExecuteMainAlarm', $delay * 1000);
                $this->SendDebug(__FUNCTION__, 'Der Hauptalarm wird in ' . $delay . ' ' . $unit . ' eingeschaltet.', 0);
            }
        }
        return $result;
    }

    public function DeactivatePreAlarm(): bool
    {
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $statusValue = $this->GetValue('Status');
        if (!$alarmSirenValue || $statusValue != 1) {
            return false;
        }
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        $this->SetValue('AlarmSiren', false);
        $this->SetValue('Status', 0);
        $result = $this->SwitchAlarmSiren(false);
        if (!$result) {
            // Revert on failure
            $this->SetValue('AlarmSiren', $alarmSirenValue);
            $this->SetValue('Status', $statusValue);
            $text = 'Fehler, der Voralarm konnte nicht ausgeschaltet werden!';
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            $text = 'Der Voralarm wurde ausgeschaltet.';
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        return $result;
    }

    public function ExecuteMainAlarm(bool $PanicAlarm): bool
    {
        $this->SetTimerInterval('ExecuteMainAlarm', 0);
        if (!$PanicAlarm) {
            if (!$this->ReadPropertyBoolean('UseMainAlarm')) {
                return false;
            }
            if ($this->CheckMuteMode()) {
                return false;
            }
            if (!$this->CheckSignallingAmount()) {
                return false;
            }
        }
        $alarmSirenValue = $this->GetValue('AlarmSiren');
        $this->SetValue('AlarmSiren', true);
        $statusValue = $this->GetValue('Status');
        $this->SetValue('Status', 2);
        $result = $this->SwitchAlarmSiren(true);
        if (!$result) {
            // Revert on failure
            $this->SetValue('AlarmSiren', $alarmSirenValue);
            $this->SetValue('Status', $statusValue);
            $text = 'Fehler, der Hauptalarm konnte nicht eingeschaltet werden!';
            if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
                $text = 'Fehler, die Alarmsirene konnte nicht eingeschaltet werden!';
            }
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        } else {
            if (!$PanicAlarm) {
                $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
            }
            $text = 'Der Hauptalarm wurde eingeschaltet.';
            if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
                $text = 'Die Alarmsirene wurde eingeschaltet.';
            }
            $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        $unit = 'Sekunden';
        $duration = $this->ReadPropertyInteger('MainAlarmDuration');
        if ($duration == 1) {
            $unit = 'Sekunde';
        }
        $text = 'Der Hauptalarm wird in ' . $duration . ' ' . $unit . ' ausgeschaltet.';
        if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
            $text = 'Die Alarmsirene wird in ' . $duration . ' ' . $unit . ' ausgeschaltet.';
        }
        $this->SendDebug(__FUNCTION__, $text, 0);
        $this->SetTimerInterval('DeactivateAlarmSiren', $duration * 1000);
        return $result;
    }

    #################### Private

    private function SwitchAlarmSiren(bool $State): bool
    {
        if ($State) {
            if ($this->CheckMaintenanceMode()) {
                return false;
            }
        }
        $result = false;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            $response = @HM_WriteValueBoolean($id, 'STATE', $State);
            if (!$response) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $response = @HM_WriteValueBoolean($id, 'STATE', $State);
            }
            $stateText = 'ausgeschaltet';
            if ($State) {
                $stateText = 'eingeschaltet';
            }
            if (!$response) {
                $text = 'Fehler, die Alarmsirene konnte nicht ' . $stateText . ' werden!';
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            } else {
                $result = true;
                $text = 'Die Alarmsirene wurde ' . $stateText . '.';
            }
            $this->SendDebug(__FUNCTION__, $text, 0);
        }
        return $result;
    }

    private function CheckSignallingAmount(): bool
    {
        $maximum = $this->ReadPropertyInteger('MainAlarmMaximumSignallingAmount');
        if ($maximum > 0) {
            if ($this->GetValue('SignallingAmount') >= $maximum) {
                $text = 'Abbruch, die maximale Anzahl der Auslösungen wurde bereits erreicht!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                $this->UpdateAlarmProtocol($text . ' (ID ' . $this->InstanceID . ')');
                return false;
            }
        }
        return true;
    }
}