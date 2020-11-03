<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */
/** @noinspection PhpUndefinedFunctionInspection */

declare(strict_types=1);

trait AS1_alarmSiren
{
    #################### Variable

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
        $result = false;
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return $result;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return $result;
        }
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $actualAlarmSirenState = $this->GetValue('AlarmSiren');
        $actualSignallingAmount = $this->GetValue('SignallingAmount');
        //Deactivate
        if (!$State) {
            $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird ausgeschaltet.', 0);
            $this->WriteAttributeBoolean('MainAlarm', false);
            $this->UpdateParameter();
            $this->DisableTimers();
            $this->SetValue('AlarmSiren', false);
            IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
            //Semaphore Enter
            if (!IPS_SemaphoreEnter($this->InstanceID . '.ToggleAlarmSiren', 5000)) {
                return $result;
            }
            $result = true;
            $response = @RequestAction($id, false);
            if (!$response) {
                IPS_Sleep(self::DELAY_MILLISECONDS);
                $response = @RequestAction($id, false);
                if (!$response) {
                    $result = false;
                    //Revert
                    $this->SetValue('AlarmSiren', $actualAlarmSirenState);
                    $this->SetValue('SignallingAmount', $actualSignallingAmount);
                    $message = 'Fehler, die Alarmsirene konnte nicht ausgeschaltet werden!';
                    $this->SendDebug(__FUNCTION__, $message, 0);
                    $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
                }
            }
            //Semaphore leave
            IPS_SemaphoreLeave($this->InstanceID . '.ToggleAlarmSiren');
            if ($result) {
                //Protocol
                $text = 'Die Alarmsirene wurde ausgeschaltet. (ID ' . $id . ')';
                $this->UpdateProtocol($text);
            }
        }
        //Activate
        if ($State) {
            //Check mute mode
            if ($this->CheckMuteMode()) {
                return $result;
            }
            //Check signaling amount
            if (!$this->CheckSignallingAmount()) {
                return $result;
            }
            //Check if main alarm is already turned on
            if ($this->CheckMainAlarm()) {
                return $result;
            }
            $this->SetValue('AlarmSiren', true);
            //Delay
            $delay = $this->ReadPropertyInteger('MainAlarmSignallingDelay');
            if ($delay > 0) {
                $this->SetTimerInterval('ActivateMainAlarm', $delay * 1000);
                $this->SendDebug(__FUNCTION__, 'Die Alarmsirene wird in ' . $delay . ' Sekunden eingeschaltet.', 0);
                if (!$actualAlarmSirenState) {
                    //Protocol
                    $text = 'Die Alarmsirene wird in ' . $delay . ' Sekunden eingeschaltet. (ID ' . $id . ')';
                    $this->UpdateProtocol($text);
                }
                //Check pre alarm
                if ($this->ReadPropertyBoolean('UsePreAlarm')) {
                    $result = $this->TriggerPreAlarm();
                }
            }
            //No delay, activate alarm siren immediately
            else {
                $result = $this->ActivateMainAlarm();
                if (!$result) {
                    //Revert
                    $this->SetValue('AlarmSiren', $actualAlarmSirenState);
                    $this->SetValue('SignallingAmount', $actualSignallingAmount);
                }
            }
        }
        return $result;
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
    public function CheckTrigger(int $SenderID): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        //Check mute mode
        if ($this->CheckMuteMode()) {
            return false;
        }
        $result = true;
        //Trigger variables
        $triggerVariables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($triggerVariables)) {
            foreach ($triggerVariables as $variable) {
                $id = $variable->ID;
                if ($SenderID == $id) {
                    $use = $variable->ID;
                    if ($use) {
                        $actualValue = intval(GetValue($id));
                        $triggerValueOn = $variable->TriggerValueOn;
                        if ($actualValue == $triggerValueOn) {
                            $result = $this->ToggleAlarmSiren(true);
                        }
                        $triggerValueOff = $variable->TriggerValueOff;
                        if ($actualValue == $triggerValueOff) {
                            $result = $this->ToggleAlarmSiren(false);
                        }
                    }
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
        $result = false;
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return $result;
        }
        //Check mute mode
        if ($this->CheckMuteMode()) {
            return $result;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return $result;
        }
        $duration = $this->ReadPropertyInteger('PreAlarmDuration');
        if ($duration == 0) {
            return $result;
        }
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.PreAlarm', 5000)) {
            return $result;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $response = @RequestAction($id, true);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @RequestAction($id, true);
            if (!$response) {
                $result = false;
                $message = 'Fehler, der Voralarm konnte nicht ausgegeben werden!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
            }
        }
        //Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.PreAlarm');
        $this->SetTimerInterval('DeactivatePreAlarm', $duration * 1000);
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Der Voralarm wurde erfolgreich ausgegeben.', 0);
        }
        return $result;
    }

    /**
     * Triggers a main alarm.
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
        $result = false;
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return $result;
        }
        //Check mute mode
        if ($this->CheckMuteMode()) {
            return $result;
        }
        //Check signaling amount
        if (!$this->CheckSignallingAmount()) {
            return $result;
        }
        //Check if the main alarm is already turned on
        if ($this->CheckMainAlarm()) {
            return $result;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return $result;
        }
        $this->WriteAttributeBoolean('MainAlarm', true);
        $this->UpdateParameter();
        $this->SetTimerInterval('ActivateMainAlarm', 0);
        $this->SetValue('AlarmSiren', true);
        $this->SetValue('SignallingAmount', $this->GetValue('SignallingAmount') + 1);
        $duration = $this->ReadPropertyInteger('MainAlarmAcousticSignallingDuration');
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.MainAlarm', 5000)) {
            return $result;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $response = @RequestAction($id, true);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @RequestAction($id, true);
            if (!$response) {
                $result = false;
                $message = 'Fehler, der Hauptalarm konnte nicht ausgegeben werden!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
            }
        }
        //Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.MainAlarm');
        if ($result) {
            $this->SendDebug(__FUNCTION__, 'Der Hauptalarm wurde erfolgreich ausgegeben.', 0);
            //Protocol
            $text = 'Die Alarmsirene wurde eingeschaltet. (ID ' . $id . ')';
            $this->UpdateProtocol($text);
        }
        $this->SetTimerInterval('DeactivateMainAlarm', $duration * 1000);
        return $result;
    }

    /**
     * Deactivates the pre alarm.
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
        $result = false;
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return $result;
        }
        //Check alarm siren
        if (!$this->CheckAlarmSiren()) {
            return $result;
        }
        IPS_Sleep($this->ReadPropertyInteger('AlarmSirenSwitchingDelay'));
        //Semaphore Enter
        if (!IPS_SemaphoreEnter($this->InstanceID . '.DeactivatePreAlarm', 5000)) {
            return $result;
        }
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        $response = @RequestAction($id, false);
        if (!$response) {
            IPS_Sleep(self::DELAY_MILLISECONDS);
            $response = @RequestAction($id, false);
            if (!$response) {
                $result = false;
                $message = 'Fehler, der Voralarm konnte nicht ausgeschaltet werden!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_ERROR);
            }
        }
        //Semaphore leave
        IPS_SemaphoreLeave($this->InstanceID . '.DeactivatePreAlarm');
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        return $result;
    }

    /**
     * Deactivates the optical Signalling
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
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
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
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        $result = true;
        $id = $this->ReadPropertyInteger('AlarmSiren');
        if ($id == 0 || @!IPS_ObjectExists($id)) {
            $result = false;
            $message = 'Abbruch, es ist keine Alarmsirene ausgewählt!';
            $this->SendDebug(__FUNCTION__, $message, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
        }
        return $result;
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
            $message = 'Abbruch, die akustische Signalisierung ist bereits eingeschaltet!';
            $this->SendDebug(__FUNCTION__, $message, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
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
        $execute = true;
        $maximum = $this->ReadPropertyInteger('MainAlarmMaximumSignallingAmount');
        if ($maximum > 0) {
            if ($this->GetValue('SignallingAmount') >= $maximum) {
                $execute = false;
                $message = 'Abbruch, die maximale Anzahl der Auslösungen wurde bereits erreicht!';
                $this->SendDebug(__FUNCTION__, $message, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $message, KL_WARNING);
            }
        }
        return $execute;
    }

    /**
     * Updates the protocol.
     *
     * @param string $Message
     */
    private function UpdateProtocol(string $Message): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt (' . microtime(true) . ')', 0);
        if ($this->CheckMaintenanceMode()) {
            return;
        }
        $protocolID = $this->ReadPropertyInteger('AlarmProtocol');
        if ($protocolID != 0 && @IPS_ObjectExists($protocolID)) {
            $timestamp = date('d.m.Y, H:i:s');
            $logText = $timestamp . ', ' . $Message;
            @APRO_UpdateMessages($protocolID, $logText, 0);
        }
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