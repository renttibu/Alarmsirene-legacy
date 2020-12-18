<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmsirene 1 (Variable)
 *
 * @prefix      AS1
 *
 * @file        AS1_alarmSiren.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @see         https://github.com/ubittner/Alarmsirene
 *
 */

declare(strict_types=1);

trait AS1_alarmSiren
{
    /**
     * Toggles the alarm siren off or on.
     *
     * @param bool $State
     * false    = off
     * true     = on
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function ToggleAlarmSiren(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wurde mit Parameter ' . json_encode($State) . ' ausgeführt (' . microtime(true) . ')', 0);
        $this->DisableTimers();
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('Variable');
        $actualAlarmSirenState = $this->GetValue('AlarmSiren');
        $actualSignallingAmount = $this->GetValue('SignallingAmount');
        //Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird ausgeschaltet', 0);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            //Semaphore Enter
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
            //Semaphore leave
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
                //Revert on failure
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
        //Activate
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
            //Check if main alarm is already turned on
            if ($this->CheckMainAlarm()) {
                return false;
            }
            //Delay
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
                //Check pre alarm (delay needed for main alarm)
                if ($this->ReadPropertyBoolean('UsePreAlarm')) {
                    $result = $this->TriggerPreAlarm();
                }
            }
            //No delay, activate alarm siren immediately
            else {
                if ($State != $actualAlarmSirenState) {
                    $result = $this->ActivateMainAlarm();
                }
            }
        }
        return $result;
    }

    /**
     * Triggers a pre alarm.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function TriggerPreAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
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
        //Semaphore Enter
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
        //Semaphore leave
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

    /**
     * Deactivates the pre alarm, normally used by timer.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function DeactivatePreAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
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
        //Semaphore leave
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

    /**
     * Activates the main alarm.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function ActivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
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
        //Check if the main alarm is already turned on
        if ($this->CheckMainAlarm()) {
            return false;
        }
        return $this->TriggerMainAlarm();
    }

    /**
     * Triggers the main alarm.
     *
     * @return bool
     * @throws Exception
     */
    public function TriggerMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->DisableTimers();
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        $this->SetValue('AlarmSiren', true);
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
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
        //Semaphore leave
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
            //Revert on failure
            $this->SetValue('AlarmSiren', false);
            $text = 'Fehler, die Alarmsirene konnte nicht eingeschaltet werden!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_ERROR);
            $this->UpdateAlarmProtocol($text . '. (ID ' . $id . ')');
        }
        return $result;
    }

    /**
     * Deactivates the alarm siren, normally used by timer.
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function DeactivateMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->SetTimerInterval('DeactivateMainAlarm', 0);
        return $this->ToggleAlarmSiren(false);
    }

    /**
     * Resets the signalling amount.
     */
    public function ResetSignallingAmount(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $this->SetValue('SignallingAmount', 0);
    }

    /**
     * Checks the trigger variable.
     *
     * @param int $SenderID
     * @param bool $ValueChanged
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function CheckTriggerVariable(int $SenderID, bool $ValueChanged): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if (!$this->CheckSwitchingVariable()) {
            return false;
        }
        $result = true;
        //Trigger variables
        $triggerVariables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($triggerVariables)) {
            foreach ($triggerVariables as $variable) {
                $id = $variable->TriggeringVariable;
                if ($SenderID == $id) {
                    if ($variable->Use) {
                        $this->SendDebug(__FUNCTION__, 'Variable ' . $id . ' ist aktiv', 0);
                        $execute = false;
                        $type = IPS_GetVariable($id)['VariableType'];
                        $trigger = $variable->Trigger;
                        $value = $variable->Value;
                        switch ($trigger) {
                            case 0: #on change (bool, integer, float, string)
                                if ($ValueChanged) {
                                    $execute = true;
                                }
                                break;

                            case 1: #on update (bool, integer, float, string)
                                $execute = true;
                                break;

                            case 2: #on limit drop (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $actualValue = GetValueInteger($id);
                                        $triggerValue = intval($value);
                                        if ($actualValue < $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                    case 2: #float
                                        $actualValue = GetValueFloat($id);
                                        $triggerValue = floatval(str_replace(',', '.', $value));
                                        if ($actualValue < $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                }
                                break;

                            case 3: #on limit exceed (integer, float)
                                switch ($type) {
                                    case 1: #integer
                                        $actualValue = GetValueInteger($id);
                                        $triggerValue = intval($value);
                                        if ($actualValue > $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                    case 2: #float
                                        $actualValue = GetValueFloat($id);
                                        $triggerValue = floatval(str_replace(',', '.', $value));
                                        if ($actualValue > $triggerValue) {
                                            $execute = true;
                                        }
                                        break;

                                }
                                break;

                            case 4: #on specific value (bool, integer, float, string)
                                switch ($type) {
                                    case 0: #bool
                                        $actualValue = GetValueBoolean($id);
                                        if ($value == 'false') {
                                            $value = '0';
                                        }
                                        $triggerValue = boolval($value);
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                    case 1: #integer
                                        $actualValue = GetValueInteger($id);
                                        $triggerValue = intval($value);
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                    case 2: #float
                                        $actualValue = GetValueFloat($id);
                                        $triggerValue = floatval(str_replace(',', '.', $value));
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                    case 3: #string
                                        $actualValue = GetValueString($id);
                                        $triggerValue = (string) $value;
                                        if ($actualValue == $triggerValue) {
                                            $condition = $variable->Condition;
                                            switch ($condition) {
                                                case 1: #trigger once
                                                    if ($ValueChanged) {
                                                        $execute = true;
                                                    }
                                                    break;

                                                case 2: #trigger every time
                                                    $execute = true;
                                            }
                                        }
                                        break;

                                }
                                break;
                        }
                        if ($execute) {
                            $action = $variable->Action;
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
                        }
                    }
                }
            }
        }
        return $result;
    }

    #################### Private

    /**
     * Checks for an existing, switching variable (alarm siren).
     *
     * @return bool
     * false    = no alarm siren
     * true     = ok
     */
    private function CheckSwitchingVariable(): bool
    {
        $id = $this->ReadPropertyInteger('Variable');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            $text = 'Abbruch, es ist keine Alarmsirene ausgewählt!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
            return false;
        }
        return true;
    }

    /**
     * Checks if the main alarm is already turned on.
     *
     * @return bool
     * false    = off
     * true     = already turned on
     */
    private function CheckMainAlarm(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $state = $this->ReadAttributeBoolean('MainAlarm');
        if ($state) {
            $text = 'Abbruch, die akustische Signalisierung ist bereits eingeschaltet!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
        }
        return $state;
    }

    /**
     * Checks the signalling amount.
     *
     * @return bool
     * false    = maximum signalling reached
     * true     = ok
     */
    private function CheckSignallingAmount(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
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

    /**
     * Sets the timer for resetting the signalling amount.
     */
    private function SetResetSignallingAmountTimer()
    {
        $timestamp = strtotime('next day midnight');
        $now = time();
        $interval = ($timestamp - $now) * 1000;
        $this->SetTimerInterval('ResetSignallingAmount', $interval);
    }
}