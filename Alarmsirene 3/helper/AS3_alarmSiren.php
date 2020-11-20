<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmsirene 3 (HomeMatic)
 *
 * @prefix      AS3
 *
 * @file        AS3_alarmSiren.php
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

trait AS3_alarmSiren
{
    #################### HM-Sec-Sir-WM

    /**
     *
     * CHANNEL  = 3, SWITCH_PANIC
     *
     * STATE:
     *
     * false    = TURN_OFF
     * true     = TURN_ON
     *
     * CHANNEL  = 4, ARMING
     *
     * ARMSTATE:
     *
     * 0        = DISARMED
     * 1        = EXTSENS_ARMED
     * 2        = ALLSENS_ARMED
     * 3        = ALARM_BLOCKED
     */

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
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $actualAlarmSirenState = $this->GetValue('AlarmSiren');
        $actualSignallingAmount = $this->GetValue('SignallingAmount');
        //Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird ausgeschaltet', 0);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            //Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.ToggleAlarmSiren', 5000)) {
                return false;
            }
            $this->SetValue('AlarmSiren', false);
            $response = @HM_WriteValueBoolean($id, 'STATE', false);
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
            IPS_SemaphoreLeave($this->InstanceID . '.ToggleAlarmSiren');
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
                return $result;
            }
            if ($this->CheckMuteMode()) {
                return $result;
            }
            if (!$this->CheckSignallingAmount()) {
                return $result;
            }
            //Check if main alarm is already turned on
            if ($this->CheckMainAlarm()) {
                return $result;
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
        if (!$this->ReadPropertyBoolean('UsePreAlarm')) {
            $this->SendDebug(__FUNCTION__, 'Der Voralarm ist nicht aktiviert!', 0);
            return false;
        }
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        if ($this->CheckMuteMode()) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('PreAlarmSiren');
        if ($id != 0 && @IPS_ObjectExists($id)) {
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            //Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.TriggerPreAlarm', 5000)) {
                return false;
            }
            $response = @HM_WriteValueInteger($id, 'ARMSTATE', 2);
            if (!$response) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $response = @HM_WriteValueInteger($id, 'ARMSTATE', 2);
                if (!$response) {
                    $result = false;
                }
            }
            //Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.TriggerPreAlarm');
        }
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
        if (!$this->ReadPropertyBoolean('UseMainAlarm')) {
            $this->SendDebug(__FUNCTION__, 'Der Hauptalarm ist nicht aktiviert!', 0);
            return false;
        }
        if (!$this->CheckAlarmSiren()) {
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
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $this->SetValue('AlarmSiren', true);
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.TriggerMainAlarm', 5000)) {
            return false;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $response = @HM_WriteValueBoolean($id, 'STATE', true);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @HM_WriteValueBoolean($id, 'STATE', true);
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
     * Deactivates the main alarm.
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
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     *
     * @throws Exception
     */
    public function CheckTriggerVariable(int $SenderID): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if (!$this->CheckAlarmSiren()) {
            return false;
        }
        $result = true;
        //Trigger variables
        $triggerVariables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($triggerVariables)) {
            foreach ($triggerVariables as $variable) {
                $id = $variable->ID;
                if ($SenderID == $id) {
                    if ($variable->Use) {
                        $actualValue = intval(GetValue($id));
                        $this->SendDebug(__FUNCTION__, 'Aktueller Wert: ' . $actualValue, 0);
                        $triggerValue = $variable->TriggerValue;
                        $this->SendDebug(__FUNCTION__, 'Auslösender Wert: ' . $triggerValue, 0);
                        if ($actualValue == $triggerValue) {
                            $triggerAction = $variable->TriggerAction;
                            switch ($triggerAction) {
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
        }
        return $result;
    }

    #################### Private

    /**
     * Checks for an existing alarm siren.
     *
     * @return bool
     * false    = no alarm siren
     * true     = ok
     */
    private function CheckAlarmSiren(): bool
    {
        $id = $this->ReadPropertyInteger('AlarmSiren');
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