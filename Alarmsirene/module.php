<?php

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

/*
 * @module      Alarmsirene
 *
 * @prefix      ASIR
 *
 * @file        module.php
 *
 * @author      Ulrich Bittner
 * @copyright   (c) 2020
 * @license    	CC BY-NC-SA 4.0
 *              https://creativecommons.org/licenses/by-nc-sa/4.0/
 *
 * @see         https://github.com/ubittner/Alarmsirene
 *
 * @guids       Library
 *              {6984F242-48A3-5594-B0D1-061D71C6B0E5}
 *
 *              Alarmsirene
 *             	{118660A6-0784-4AD9-81D3-218BD03B1FF5}
 */

declare(strict_types=1);

// Include
include_once __DIR__ . '/helper/autoload.php';

class Alarmsirene extends IPSModule
{
    // Helper
    use ASIR_alarmSiren;
    use ASIR_backupRestore;

    // Constants
    private const DELAY_MILLISECONDS = 250;
    private const ALARMSIRENE_LIBRARY_GUID = '{6984F242-48A3-5594-B0D1-061D71C6B0E5}';
    private const ALARMSIRENE_MODULE_GUID = '{118660A6-0784-4AD9-81D3-218BD03B1FF5}';

    public function Create()
    {
        // Never delete this line!
        parent::Create();
        $this->RegisterProperties();
        $this->CreateProfiles();
        $this->RegisterVariables();
        $this->RegisterAttributes();
        $this->RegisterTimers();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();
        $this->DeleteProfiles();
    }

    public function ApplyChanges()
    {
        // Wait until IP-Symcon is started
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        // Never delete this line!
        parent::ApplyChanges();
        // Check runlevel
        if (IPS_GetKernelRunlevel() != KR_READY) {
            return;
        }
        $this->SetOptions();
        $this->ResetAttributes();
        $this->DisableTimers();
        $this->SetValue('SignallingAmount', 0);
        $this->ValidateConfiguration();
        $this->CheckMaintenanceMode();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $this->SendDebug(__FUNCTION__, $TimeStamp . ', SenderID: ' . $SenderID . ', Message: ' . $Message . ', Data: ' . print_r($Data, true), 0);
        if (!empty($Data)) {
            foreach ($Data as $key => $value) {
                $this->SendDebug(__FUNCTION__, 'Data[' . $key . '] = ' . json_encode($value), 0);
            }
        }
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->KernelReady();
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $moduleInfo = [];
        $library = IPS_GetLibrary(self::ALARMSIRENE_LIBRARY_GUID);
        $module = IPS_GetModule(self::ALARMSIRENE_MODULE_GUID);
        $moduleInfo['name'] = $module['ModuleName'];
        $moduleInfo['version'] = $library['Version'] . '-' . $library['Build'];
        $moduleInfo['date'] = date('d.m.Y', $library['Date']);
        $moduleInfo['time'] = date('H:i', $library['Date']);
        $moduleInfo['developer'] = $library['Author'];
        $formData['elements'][0]['items'][2]['caption'] = "Instanz ID:\t\t" . $this->InstanceID;
        $formData['elements'][0]['items'][3]['caption'] = "Modul:\t\t\t" . $moduleInfo['name'];
        $formData['elements'][0]['items'][4]['caption'] = "Version:\t\t\t" . $moduleInfo['version'];
        $formData['elements'][0]['items'][5]['caption'] = "Datum:\t\t\t" . $moduleInfo['date'];
        $formData['elements'][0]['items'][6]['caption'] = "Uhrzeit:\t\t\t" . $moduleInfo['time'];
        $formData['elements'][0]['items'][7]['caption'] = "Entwickler:\t\t" . $moduleInfo['developer'];
        $formData['elements'][0]['items'][8]['caption'] = "Präfix:\t\t\tASIR";
        return json_encode($formData);
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    #################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AlarmSiren':
                $this->ToggleAlarmSiren($Value);
                break;

            case 'ResetSignallingAmount':
                $this->ResetSignallingAmount($Value);
                break;

        }
    }

    #################### Private

    private function KernelReady()
    {
        $this->ApplyChanges();
    }

    private function RegisterProperties(): void
    {
        $this->RegisterPropertyString('Note', '');
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        // Visibility
        $this->RegisterPropertyBoolean('EnableAlarmSiren', true);
        $this->RegisterPropertyBoolean('EnableAlarmSirenState', true);
        $this->RegisterPropertyBoolean('EnableSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableResetSignallingAmount', true);
        // Alarm siren
        $this->RegisterPropertyString('AlarmSirens', '[]');
        // Signaling variants
        $this->RegisterPropertyInteger('AcousticSignalPreAlarm', 10);
        $this->RegisterPropertyInteger('OpticalSignalPreAlarm', 3);
        $this->RegisterPropertyInteger('AcousticSignalMainAlarm', 3);
        $this->RegisterPropertyInteger('OpticalSignalMainAlarm', 3);
        // Signalling delay
        $this->RegisterPropertyInteger('SignallingDelay', 0);
        $this->RegisterPropertyBoolean('UsePreAlarm', false);
        // Signalling duration
        $this->RegisterPropertyInteger('AcousticSignallingDuration', 180);
        $this->RegisterPropertyInteger('OpticalSignallingDuration', 5);
        // Signalling limit
        $this->RegisterPropertyInteger('MaximumSignallingAmount', 3);
        // Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
    }

    private function CreateProfiles(): void
    {
        // Alarm siren state
        $profile = 'ASIR.' . $this->InstanceID . '.AlarmSirenState';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileIcon($profile, '');
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Information', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Warning', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 2, 'Verzögert', 'Clock', 0xFFFF00);
        // Reset signalling amount
        $profile = 'ASIR.' . $this->InstanceID . '.ResetSignallingAmount';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Repeat', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'Reset', 'Repeat', 0xFF0000);
    }

    private function DeleteProfiles(): void
    {
        $profiles = ['AlarmSirenState', 'ResetSignallingAmount'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'ASIR.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
    }

    private function RegisterVariables(): void
    {
        // Alarm siren
        $this->RegisterVariableBoolean('AlarmSiren', 'Alarmsirene', '~Switch', 10);
        $this->EnableAction('AlarmSiren');
        $id = $this->GetIDForIdent('AlarmSiren');
        IPS_SetIcon($id, 'Alert');
        // Alarm siren state
        $profile = 'ASIR.' . $this->InstanceID . '.AlarmSirenState';
        $this->RegisterVariableInteger('AlarmSirenState', 'Status', $profile, 20);
        // Signalling amount
        $this->RegisterVariableInteger('SignallingAmount', 'Anzahl der Auslösungen', '', 30);
        $this->SetValue('SignallingAmount', 0);
        $id = $this->GetIDForIdent('SignallingAmount');
        IPS_SetIcon($id, 'Warning');
        // Reset signalling amount
        $profile = 'ASIR.' . $this->InstanceID . '.ResetSignallingAmount';
        $this->RegisterVariableBoolean('ResetSignallingAmount', 'Rückstellung der Auslösungen', $profile, 40);
        $this->EnableAction('ResetSignallingAmount');
    }

    private function SetOptions(): void
    {
        // Alarm siren
        $id = $this->GetIDForIdent('AlarmSiren');
        $use = $this->ReadPropertyBoolean('EnableAlarmSiren');
        IPS_SetHidden($id, !$use);
        // Alarm siren state
        $id = $this->GetIDForIdent('AlarmSirenState');
        $use = $this->ReadPropertyBoolean('EnableAlarmSirenState');
        IPS_SetHidden($id, !$use);
        // Signalling amount
        $id = $this->GetIDForIdent('SignallingAmount');
        $use = $this->ReadPropertyBoolean('EnableSignallingAmount');
        IPS_SetHidden($id, !$use);
        // Reset signalling amount
        $id = $this->GetIDForIdent('ResetSignallingAmount');
        $use = $this->ReadPropertyBoolean('EnableResetSignallingAmount');
        IPS_SetHidden($id, !$use);
    }

    private function RegisterTimers(): void
    {
        $this->RegisterTimer('ActivateAcousticSignalling', 0, 'ASIR_ActivateAcousticSignalling(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateAcousticSignalling', 0, 'ASIR_DeactivateAcousticSignalling(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateOpticalSignalling', 0, 'ASIR_DeactivateOpticalSignalling(' . $this->InstanceID . ');');
    }

    private function DisableTimers(): void
    {
        $this->SetTimerInterval('ActivateAcousticSignalling', 0);
        $this->SetTimerInterval('DeactivateAcousticSignalling', 0);
        $this->SetTimerInterval('DeactivateOpticalSignalling', 0);
    }

    private function RegisterAttributes(): void
    {
        $this->RegisterAttributeBoolean('AcousticSignallingActive', false);
    }

    private function ResetAttributes(): void
    {
        $this->WriteAttributeBoolean('AcousticSignallingActive', false);
    }

    private function ValidateConfiguration(): void
    {
        $status = 102;
        // Check acoustical and optical duration
        $acousticDuration = $this->ReadPropertyInteger('AcousticSignallingDuration');
        $opticalDuration = $this->ReadPropertyInteger('OpticalSignallingDuration') * 60;
        if ($opticalDuration < $acousticDuration) {
            $status = 200;
            $this->LogMessage('Die Dauer der optischen Signalisierung ist zu gering!', KL_ERROR);
        }
        $this->SetStatus($status);
    }

    private function CheckMaintenanceMode(): bool
    {
        $result = false;
        $status = 102;
        if ($this->ReadPropertyBoolean('MaintenanceMode')) {
            $result = true;
            $status = 104;
            $this->SendDebug(__FUNCTION__, 'Abbruch, der Wartungsmodus ist aktiv!', 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', Abbruch, der Wartungsmodus ist aktiv!', KL_WARNING);
        }
        $this->SetStatus($status);
        IPS_SetDisabled($this->InstanceID, $result);
        return $result;
    }
}