<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license    	CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/Alarmsirene%202
 */

/** @noinspection PhpUnusedPrivateMethodInspection */
/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

trait AS2_muteMode
{
    public function ToggleMuteMode(bool $State): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
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

    public function StartMuteMode(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->ToggleMuteMode(true);
        $this->SetMuteModeTimer();
    }

    public function StopMuteMode(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $this->ToggleMuteMode(false);
        $this->SetMuteModeTimer();
    }

    #################### Private

    private function SetMuteModeTimer(): void
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $use = $this->ReadPropertyBoolean('UseAutomaticMuteMode');
        // Start
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('MuteModeStartTime');
        }
        $this->SetTimerInterval('StartMuteMode', $milliseconds);
        // End
        $milliseconds = 0;
        if ($use) {
            $milliseconds = $this->GetInterval('MuteModeEndTime');
        }
        $this->SetTimerInterval('StopMuteMode', $milliseconds);
    }

    private function GetInterval(string $TimerName): int
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
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

    private function CheckMuteModeTimer(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        if (!$this->ReadPropertyBoolean('UseAutomaticMuteMode')) {
            return false;
        }
        $start = $this->GetTimerInterval('StartMuteMode');
        $stop = $this->GetTimerInterval('StopMuteMode');
        if ($start > $stop) {
            $this->ToggleMuteMode(true);
            return true;
        } else {
            $this->ToggleMuteMode(false);
            return false;
        }
    }

    private function CheckMuteMode(): bool
    {
        $this->SendDebug(__FUNCTION__, 'Die Methode wird ausgeführt.', 0);
        $muteMode = boolval($this->GetValue('MuteMode'));
        if ($muteMode) {
            $text = 'Abbruch, die Stummschaltung ist aktiv!';
            $this->SendDebug(__FUNCTION__, $text, 0);
        }
        return $muteMode;
    }
}