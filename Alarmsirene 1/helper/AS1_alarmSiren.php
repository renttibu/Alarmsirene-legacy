<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/Alarmsirene%201
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait AS1_alarmSiren
{
    public function ToggleAlarmSiren(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' ausgeführt.', 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('Variable');
        $actualAlarmSirenState = $this->GetValue('AlarmSiren');
        $actualSignallingAmount = $this->GetValue('SignallingAmount');
        // Deactivate
        if (!$State) {
            $this->SetTimerInterval('DeactivatePreAlarm', 0);
            $this->SetTimerInterval('ActivateMainAlarm', 0);
            $this->SetTimerInterval('DeactivateMainAlarm', 0);
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird ausgeschaltet', 0);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            // Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.ToggleAlarmSirenOff', 5000)) {
                return false;
            }
            $this->SetValue('AlarmSiren', false);
            $response = @RequestAction($id, false);
            if (!$response) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $response = @RequestAction($id, false);
                if (!$response) {
                    IPS_Sleep(self::DELAY_MILLISECONDS * 2);
                    $response = @RequestAction($id, false);
                    if (!$response) {
                        $result = false;
                    }
                }
            }
            // Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.ToggleAlarmSirenOff');
            if ($result) {
                $this->WriteAttributeBoolean('MainAlarm', false);
                $this->UpdateParameter();
                $text = 'Die Alarmsirene wurde ausgeschaltet';
                $this->SendDebug(__FUNCTION__, $text, 0);
                if ($State != $actualAlarmSirenState) {
                    $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
                }
            } else {
                // Revert on failure
                $this->SetValue('AlarmSiren', $actualAlarmSirenState);
                $this->SetValue('SignallingAmount', $actualSignallingAmount);
                $text = 'Fehler, die Alarmsirene konnte nicht ausgeschaltet werden!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
                if ($State != $actualAlarmSirenState) {
                    $this->UpdateAlarmProtocol($text . ' (ID ' . $id . ')');
                }
            }
        }
        // Activate
        if ($State) {
            if ($this->CheckMaintenanceMode()) {
                return false;
            }
            if ($this->CheckMuteMode()) {
                return false;
            }
            if (!$this->CheckSignallingAmount()) {
                return false;
            }
            // Check if main alarm is already turned on
            if ($this->CheckMainAlarm()) {
                return false;
            }
            // Delay
            $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($delay > 0) {
                $this->SetTimerInterval('ActivateMainAlarm', $delay * 1000);
                $unit = 'Sekunden';
                if ($delay == 1) {
                    $unit = 'Sekunde';
                }
                $this->SetValue('AlarmSiren', true);
                $text = 'Die Alarmsirene wird in ' . $delay . ' ' . $unit . ' eingeschaltet';
                $this->SendDebug(__FUNCTION__, $text, 0);
                if (!$actualAlarmSirenState) {
                    if ($State != $actualAlarmSirenState) {
                        $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
                    }
                }
                // Check pre alarm (delay needed for main alarm)
                if ($this->ReadPropertyBoolean('UsePreAlarm')) {
                    $result = $this->TriggerPreAlarm();
                }
            }
            // No delay, activate alarm siren immediately
            else {
                if ($State != $actualAlarmSirenState) {
                    $result = $this->ActivateMainAlarm();
                }
            }
        }
        return $result;
    }

    public function TriggerPreAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckMuteMode()) {
            return false;
        }
        $duration = $this->ReadPropertyInteger('PreAlarmDuration');
        if ($duration == 0) {
            return false;
        }
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        // Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.TriggerPreAlarm', 5000)) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('Variable');
        $response = @RequestAction($id, true);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @RequestAction($id, true);
            if (!$response) {
                $result = false;
            }
        }
        // Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.TriggerPreAlarm');
        $this->SetTimerInterval('DeactivatePreAlarm', $duration * 1000);
        if ($result) {
            $text = 'Der Voralarm wurde eingeschaltet';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
        } else {
            $text = 'Fehler, der Voralarm konnte nicht eingeschaltet werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
        }
        return $result;
    }

    public function DeactivatePreAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        // Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.DeactivatePreAlarm', 5000)) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('Variable');
        $response = @RequestAction($id, false);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @RequestAction($id, false);
            if (!$response) {
                $result = false;
            }
        }
        // Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.DeactivatePreAlarm');
        if ($result) {
            $text = 'Der Voralarm wurde ausgeschaltet';
            $this->SendDebug(__FUNCTION__, $text, 0);
        } else {
            $text = 'Fehler, der Voralarm konnte nicht ausgeschaltet werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
        }
        return $result;
    }

    public function ActivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckMuteMode()) {
            return false;
        }
        if (!$this->CheckSignallingAmount()) {
            return false;
        }
        // Check if the main alarm is already turned on
        if ($this->CheckMainAlarm()) {
            return false;
        }
        return $this->TriggerMainAlarm();
    }

    public function TriggerMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        $this->SetValue('AlarmSiren', true);
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        // Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.TriggerMainAlarm', 5000)) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('Variable');
        $response = @RequestAction($id, true);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @RequestAction($id, true);
            if (!$response) {
                $result = false;
            }
        }
        // Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.TriggerMainAlarm');
        $duration = $this->ReadPropertyInteger('MainAlarmAcousticSignallingDuration');
        $this->SetTimerInterval('DeactivateMainAlarm', $duration * 1000);
        if ($result) {
            $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
            $this->WriteAttributeBoolean('MainAlarm', true);
            $this->UpdateParameter();
            $text = 'Die Alarmsirene wurde eingeschaltet';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
            $unit = 'Sekunden';
            if ($duration == 1) {
                $unit = 'Sekunde';
            }
            $text = 'Die Alarmsirene wird in ' . $duration . ' ' . $unit . ' ausgeschaltet';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
        } else {
            // Revert on failure
            $this->SetValue('AlarmSiren', false);
            $text = 'Fehler, die Alarmsirene konnte nicht eingeschaltet werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
        }
        return $result;
    }

    public function DeactivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
        return $this->ToggleAlarmSiren(false);
    }

    public function CheckTriggerVariable(int $SenderID, bool $ValueChanged): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->SendDebug(__FUNCTION__, 'Sender: ' . $SenderID . ', Wert hat sich geändert: ' . json_encode($ValueChanged), 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        $vars = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (empty($vars)) {
            return false;
        }
        $result = false;
        foreach ($vars as $var) {
            $execute = false;
            $id = $var->ID;
            if ($id != 0 && @IPS_ObjectExists($id)) {
                if ($var->Use) {
                    $this->SendDebug(__FUNCTION__, 'Variable: ' . $id . ' ist aktiviert', 0);
                    $type = IPS_GetVariable($id)['VariableType'];
                    $value = $var->Value;
                    switch ($var->Trigger) {
                        case 0: # on change (bool, integer, float, string)
                            $this->SendDebug(__FUNCTION__, 'Bei Änderung (bool, integer, float, string)', 0);
                            if ($ValueChanged) {
                                $execute = true;
                            }
                            break;

                        case 1: # on update (bool, integer, float, string)
                            $this->SendDebug(__FUNCTION__, 'Bei Aktualisierung (bool, integer, float, string)', 0);
                            $execute = true;
                            break;

                        case 2: # on limit drop, once (integer, float)
                            switch ($type) {
                                case 1: # integer
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (integer)', 0);
                                    if ($ValueChanged) {
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueInteger($SenderID) < intval($value)) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                                case 2: # float
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (float)', 0);
                                    if ($ValueChanged) {
                                        if (GetValueFloat($SenderID) < floatval(str_replace(',', '.', $value))) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                            }
                            break;

                        case 3: # on limit drop, every time (integer, float)
                            switch ($type) {
                                case 1: # integer
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (integer)', 0);
                                    if ($value == 'false') {
                                        $value = '0';
                                    }
                                    if ($value == 'true') {
                                        $value = '1';
                                    }
                                    if (GetValueInteger($SenderID) < intval($value)) {
                                        $execute = true;
                                    }
                                    break;

                                case 2: # float
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (float)', 0);
                                    if (GetValueFloat($SenderID) < floatval(str_replace(',', '.', $value))) {
                                        $execute = true;
                                    }
                                    break;

                            }
                            break;

                        case 4: # on limit exceed, once (integer, float)
                            switch ($type) {
                                case 1: # integer
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (integer)', 0);
                                    if ($ValueChanged) {
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueInteger($SenderID) > intval($value)) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                                case 2: # float
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, einmalig (float)', 0);
                                    if ($ValueChanged) {
                                        if (GetValueFloat($SenderID) > floatval(str_replace(',', '.', $value))) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                            }
                            break;

                        case 5: # on limit exceed, every time (integer, float)
                            switch ($type) {
                                case 1: # integer
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (integer)', 0);
                                    if ($value == 'false') {
                                        $value = '0';
                                    }
                                    if ($value == 'true') {
                                        $value = '1';
                                    }
                                    if (GetValueInteger($SenderID) > intval($value)) {
                                        $execute = true;
                                    }
                                    break;

                                case 2: # float
                                    $this->SendDebug(__FUNCTION__, 'Bei Grenzunterschreitung, mehrmalig (float)', 0);
                                    if (GetValueFloat($SenderID) > floatval(str_replace(',', '.', $value))) {
                                        $execute = true;
                                    }
                                    break;

                            }
                            break;

                        case 6: # on specific value, once (bool, integer, float, string)
                            switch ($type) {
                                case 0: # bool
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (bool)', 0);
                                    if ($ValueChanged) {
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if (GetValueBoolean($SenderID) == boolval($value)) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                                case 1: # integer
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (integer)', 0);
                                    if ($ValueChanged) {
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        if ($value == 'true') {
                                            $value = '1';
                                        }
                                        if (GetValueInteger($SenderID) == intval($value)) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                                case 2: # float
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (float)', 0);
                                    if ($ValueChanged) {
                                        if (GetValueFloat($SenderID) == floatval(str_replace(',', '.', $value))) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                                case 3: # string
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, einmalig (string)', 0);
                                    if ($ValueChanged) {
                                        if (GetValueString($SenderID) == (string) $value) {
                                            $execute = true;
                                        }
                                    }
                                    break;

                            }
                            break;

                        case 7: # on specific value, every time (bool, integer, float, string)
                            switch ($type) {
                                case 0: # bool
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (bool)', 0);
                                    if ($value == 'false') {
                                        $value = '0';
                                    }
                                    if (GetValueBoolean($SenderID) == boolval($value)) {
                                        $execute = true;
                                    }
                                    break;

                                case 1: # integer
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (integer)', 0);
                                    if ($value == 'false') {
                                        $value = '0';
                                    }
                                    if ($value == 'true') {
                                        $value = '1';
                                    }
                                    if (GetValueInteger($SenderID) == intval($value)) {
                                        $execute = true;
                                    }
                                    break;

                                case 2: # float
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (float)', 0);
                                    if (GetValueFloat($SenderID) == floatval(str_replace(',', '.', $value))) {
                                        $execute = true;
                                    }
                                    break;

                                case 3: # string
                                    $this->SendDebug(__FUNCTION__, 'Bei bestimmten Wert, mehrmalig (string)', 0);
                                    if (GetValueString($SenderID) == (string) $value) {
                                        $execute = true;
                                    }
                                    break;

                            }
                            break;

                    }
                    $this->SendDebug(__FUNCTION__, 'Bedingung erfüllt: ' . json_encode($execute), 0);
                    if ($execute) {
                        $action = $var->Action;
                        switch ($action) {
                            case 0:
                                $this->SendDebug(__FUNCTION__, 'Aktion: Alarmsirene ausschalten', 0);
                                $result = $this->ToggleAlarmSiren(false);
                                break;

                            case 1:
                                $this->SendDebug(__FUNCTION__, 'Aktion: Alarmsirene einschalten', 0);
                                if ($this->CheckMaintenanceMode()) {
                                    return false;
                                }
                                if ($this->CheckMuteMode()) {
                                    return false;
                                }
                                $result = $this->ToggleAlarmSiren(true);
                                break;

                            case 2:
                                $this->SendDebug(__FUNCTION__, 'Aktion: Panikalarm', 0);
                                if ($this->CheckMaintenanceMode()) {
                                    return false;
                                }
                                $result = $this->TriggerMainAlarm();
                                break;

                            default:
                                $this->SendDebug(__FUNCTION__, 'Es soll keine Aktion erfolgen!', 0);
                        }
                    } else {
                        $this->SendDebug(__FUNCTION__, 'Keine Übereinstimmung!', 0);
                    }
                }
            }
        }
        return $result;
    }

    #################### Private

    private function CheckSwitchingVariable(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $id = $this->ReadPropertyInteger('Variable');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            $text = 'Abbruch, es ist keine Alarmsirene ausgewählt!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
            return false;
        }
        return true;
    }

    private function CheckMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $state = $this->ReadAttributeBoolean('MainAlarm');
        if ($state) {
            $text = 'Abbruch, die akustische Signalisierung ist bereits eingeschaltet!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
        }
        return $state;
    }

    private function CheckSignallingAmount(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $maximum = $this->ReadPropertyInteger('MainAlarmMaximumSignallingAmount');
        if ($maximum > 0) {
            if ($this->GetValue('SignallingAmount') >= $maximum) {
                $text = 'Abbruch, die maximale Anzahl der Auslösungen wurde bereits erreicht!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
                return false;
            }
        }
        return true;
    }
}