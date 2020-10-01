<?php

/** @noinspection PhpUnused */
/** @noinspection DuplicatedCode */

declare(strict_types=1);

trait AS2_muteMode
{
    /**
     * Toggles the mute mode off or on.
     *
     * @param bool $State
     * false    = off
     * true     = on
     *
     * @return bool
     * false    = an error occurred
     * true     = successful
     */
    public function ToggleMuteMode(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        //Check maintenance mode
        if ($this->CheckMaintenanceMode()) {
            return false;
        }
        $result = true;
        $this->SetValue('MuteMode', $State);
        if ($State) {
            $result = $this->ToggleAlarmSiren(false);
        }
        return $result;
    }

    /**
     * Starts the mute mode, used by timer.
     */
    public function StartMuteMode(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->ToggleMuteMode(true);
        $this->SetMuteModeTimer();
    }

    /**
     * Stops the night mode, used by timer.
     */
    public function StopMuteMode(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $this->ToggleMuteMode(false);
        $this->SetMuteModeTimer();
    }

    #################### Private

    /**
     * Sets the timer interval for the automatic mute mode.
     */
    private function SetMuteModeTimer(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $use = $this->ReadPropertyBoolean('UseAutomaticMuteMode');
        //Start
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('MuteModeStartTime');
        }
        $this->SetTimerInterval('StartMuteMode', $milliseconds);
        //End
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('MuteModeEndTime');
        }
        $this->SetTimerInterval('StopMuteMode', $milliseconds);
    }

    /**
     * Gets the interval for a timer.
     *
     * @param string $TimerName
     *
     * @return int
     */
    private function GetInterval(string $TimerName): int
    {
        $timer = json_decode($this->ReadPropertyString($TimerName));
        $now = time();
        $hour = $timer->hour;
        $minute = $timer->minute;
        $second = $timer->second;
        $definedTime = $hour . ':' . $minute . ':' . $second;
        if (time() >= strtotime($definedTime)) {
            $timestamp = mktime($hour, $minute, $second, (int) date('n'), (int) date('j') + 1, (int) date('Y'));
        } else {
            $timestamp = mktime($hour, $minute, $second, (int) date('n'), (int) date('j'), (int) date('Y'));
        }
        return ($timestamp - $now) * 1000;
    }

    /**
     * Checks the state of the automatic mute mode.
     */
    private function CheckMuteModeTimer(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt. (' . microtime(true) . ')', 0);
        $start = $this->GetTimerInterval('StartMuteMode');
        $stop = $this->GetTimerInterval('StopMuteMode');
        if ($start > $stop) {
            $this->ToggleMuteMode(true);
        } else {
            $this->ToggleMuteMode(false);
        }
    }
}