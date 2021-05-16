<?php

/*
 * @author      Ulrich Bittner
 * @copyright   (c) 2020, 2021
 * @license     CC BY-NC-SA 4.0
 * @see         https://github.com/ubittner/Alarmsirene/tree/master/Alarmsirene
 */

/** @noinspection DuplicatedCode */
/** @noinspection PhpUnused */

declare(strict_types=1);

include_once __DIR__ . '/helper/autoload.php';

class Alarmsirene extends IPSModule
{
    // Helper
    use AS_alarmProtocol;
    use AS_alarmSiren;
    use AS_backupRestore;
    use AS_muteMode;
    use AS_triggerVariable;

    // Constants
    private const DELAY_MILLISECONDS = 250;

    public function Create()
    {
        // Never delete this line!
        parent::Create();

        // Properties
        // Functions
        $this->RegisterPropertyBoolean('MaintenanceMode', false);
        $this->RegisterPropertyBoolean('EnableAlarmSiren', true);
        $this->RegisterPropertyBoolean('EnableStatus', true);
        $this->RegisterPropertyBoolean('EnableSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableResetSignallingAmount', true);
        $this->RegisterPropertyBoolean('EnableMuteMode', true);
        // Alarm siren
        $this->RegisterPropertyInteger('AlarmSirenAcousticSignal', 0);
        $this->RegisterPropertyInteger('AlarmSirenAcousticSignalSwitchingDelay', 0);
        $this->RegisterPropertyInteger('AlarmSirenOpticalSignal', 0);
        $this->RegisterPropertyInteger('AlarmSirenOpticalSignalSwitchingDelay', 0);
        // Pre alarm
        $this->RegisterPropertyBoolean('UsePreAlarm', true);
        $this->RegisterPropertyInteger('PreAlarmDuration', 3);
        $this->RegisterPropertyBoolean('UsePreAlarmAcousticSignal', true);
        $this->RegisterPropertyBoolean('UsePreAlarmOpticalSignal', true);
        // Main alarm
        $this->RegisterPropertyBoolean('UseMainAlarm', true);
        $this->RegisterPropertyInteger('MainAlarmSignallingDelay', 30);
        $this->RegisterPropertyInteger('MainAlarmDuration', 180);
        $this->RegisterPropertyInteger('MainAlarmMaximumSignallingAmount', 3);
        $this->RegisterPropertyBoolean('UseMainAlarmAcousticSignal', true);
        $this->RegisterPropertyBoolean('UseMainAlarmOpticalSignal', true);
        // Post alarm
        $this->RegisterPropertyBoolean('UsePostAlarm', true);
        $this->RegisterPropertyInteger('PostAlarmDuration', 5);
        $this->RegisterPropertyBoolean('UsePostAlarmOpticalSignal', true);
        // Trigger Variables
        $this->RegisterPropertyString('TriggerVariables', '[]');
        // Alarm protocol
        $this->RegisterPropertyInteger('AlarmProtocol', 0);
        // Mute mode
        $this->RegisterPropertyBoolean('UseAutomaticMuteMode', false);
        $this->RegisterPropertyString('MuteModeStartTime', '{"hour":22,"minute":0,"second":0}');
        $this->RegisterPropertyString('MuteModeEndTime', '{"hour":6,"minute":0,"second":0}');

        // Variables
        // Alarm siren
        $id = @$this->GetIDForIdent('AlarmSiren');
        $this->RegisterVariableBoolean('AlarmSiren', 'Alarmsirene', '~Switch', 10);
        $this->EnableAction('AlarmSiren');
        if ($id == false) {
            IPS_SetIcon(@$this->GetIDForIdent('AlarmSiren'), 'Alert');
        }
        // Status
        $profile = 'AS.' . $this->InstanceID . '.Status';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
            IPS_SetVariableProfileIcon($profile, 'Speaker');
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', '', 0x00FF00);
        IPS_SetVariableProfileAssociation($profile, 1, 'Voralarm', '', 0xFFFF00);
        IPS_SetVariableProfileAssociation($profile, 2, 'Hauptalarm', '', 0xFF0000);
        IPS_SetVariableProfileAssociation($profile, 3, 'Nachalarm', '', 0xFFFF00);
        $this->RegisterVariableInteger('Status', 'Status', $profile, 20);
        // Signalling amount
        $id = @$this->GetIDForIdent('SignallingAmount');
        $this->RegisterVariableInteger('SignallingAmount', 'Auslösungen', '', 30);
        if ($id == false) {
            IPS_SetIcon(@$this->GetIDForIdent('SignallingAmount'), 'Warning');
        }
        // Reset signalling amount
        $profile = 'AS.' . $this->InstanceID . '.ResetSignallingAmount';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 1);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Reset', 'Repeat', 0xFF0000);
        $this->RegisterVariableInteger('ResetSignallingAmount', 'Rückstellung', $profile, 40);
        $this->EnableAction('ResetSignallingAmount');
        // Mute mode
        $profile = 'AS.' . $this->InstanceID . '.MuteMode.Reversed';
        if (!IPS_VariableProfileExists($profile)) {
            IPS_CreateVariableProfile($profile, 0);
        }
        IPS_SetVariableProfileAssociation($profile, 0, 'Aus', 'Speaker', -1);
        IPS_SetVariableProfileAssociation($profile, 1, 'An', 'Speaker', 0x00FF00);
        $this->RegisterVariableBoolean('MuteMode', 'Stummschaltung', $profile, 50);
        $this->EnableAction('MuteMode');

        // Timers
        $this->RegisterTimer('DeactivatePreAlarm', 0, 'AS_DeactivatePreAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('ExecuteMainAlarm', 0, 'AS_ExecuteMainAlarm(' . $this->InstanceID . ', false);');
        $this->RegisterTimer('ExecutePostAlarm', 0, 'AS_ExecutePostAlarm(' . $this->InstanceID . ');');
        $this->RegisterTimer('DeactivateAlarmSiren', 0, 'AS_ToggleAlarmSiren(' . $this->InstanceID . ', false);');
        $this->RegisterTimer('ResetSignallingAmount', 0, 'AS_ResetSignallingAmount(' . $this->InstanceID . ');');
        $this->RegisterTimer('StartMuteMode', 0, 'AS_StartMuteMode(' . $this->InstanceID . ');');
        $this->RegisterTimer('StopMuteMode', 0, 'AS_StopMuteMode(' . $this->InstanceID . ',);');
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

        // Options
        IPS_SetHidden($this->GetIDForIdent('AlarmSiren'), !$this->ReadPropertyBoolean('EnableAlarmSiren'));
        IPS_SetHidden($this->GetIDForIdent('Status'), !$this->ReadPropertyBoolean('EnableStatus'));
        IPS_SetHidden($this->GetIDForIdent('SignallingAmount'), !$this->ReadPropertyBoolean('EnableSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('ResetSignallingAmount'), !$this->ReadPropertyBoolean('EnableResetSignallingAmount'));
        IPS_SetHidden($this->GetIDForIdent('MuteMode'), !$this->ReadPropertyBoolean('EnableMuteMode'));

        // Reset
        $this->SetTimerInterval('DeactivatePreAlarm', 0);
        $this->SetTimerInterval('ExecuteMainAlarm', 0);
        $this->SetTimerInterval('ExecutePostAlarm', 0);
        $this->SetTimerInterval('DeactivateAlarmSiren', 0);
        $this->SetTimerInterval('ResetSignallingAmount', (strtotime('next day midnight') - time()) * 1000);
        $this->SetValue('SignallingAmount', 0);
        if ($this->ToggleAlarmSiren(false)) {
            $this->SetValue('Status', 0);
        }

        // Delete all references
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        // Delete all registrations
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message == VM_UPDATE) {
                    $this->UnregisterMessage($senderID, VM_UPDATE);
                }
            }
        }

        // Validation
        if (!$this->ValidateConfiguration()) {
            return;
        }

        // Register references and update messages
        $properties = ['AlarmSirenAcousticSignal', 'AlarmSirenOpticalSignal', 'AlarmProtocol'];
        foreach ($properties as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id != 0 && @IPS_ObjectExists($id)) {
                $this->RegisterReference($id);
            }
        }
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        foreach ($variables as $variable) {
            if ($variable->Use) {
                if ($variable->ID != 0 && @IPS_ObjectExists($variable->ID)) {
                    $this->RegisterReference($variable->ID);
                    $this->RegisterMessage($variable->ID, VM_UPDATE);
                }
            }
        }

        $this->SetMuteModeTimer();
        $this->CheckMuteModeTimer();
    }

    public function Destroy()
    {
        // Never delete this line!
        parent::Destroy();

        // Delete profiles
        $profiles = ['Status', 'ResetSignallingAmount', 'MuteMode.Reversed'];
        if (!empty($profiles)) {
            foreach ($profiles as $profile) {
                $profileName = 'AS.' . $this->InstanceID . '.' . $profile;
                if (IPS_VariableProfileExists($profileName)) {
                    IPS_DeleteVariableProfile($profileName);
                }
            }
        }
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

            case VM_UPDATE:

                // $Data[0] = actual value
                // $Data[1] = value changed
                // $Data[2] = last value
                // $Data[3] = timestamp actual value
                // $Data[4] = timestamp value changed
                // $Data[5] = timestamp last value

                // Check trigger
                $valueChanged = 'false';
                if ($Data[1]) {
                    $valueChanged = 'true';
                }
                $scriptText = 'AS_CheckTriggerVariable(' . $this->InstanceID . ', ' . $SenderID . ', ' . $valueChanged . ');';
                @IPS_RunScriptText($scriptText);
                break;

        }
    }

    public function GetConfigurationForm()
    {
        $formData = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        // Alarm siren acoustic signal
        $id = $this->ReadPropertyInteger('AlarmSirenAcousticSignal');
        $enabled = false;
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $enabled = true;
        }
        $formData['elements'][1]['items'][0] = [
            'type'  => 'RowLayout',
            'items' => [$formData['elements'][1]['items'][0]['items'][0] = [
                'type'    => 'SelectVariable',
                'name'    => 'AlarmSirenAcousticSignal',
                'caption' => 'Akustisches Signal',
                'width'   => '600px',
            ],
                $formData['elements'][1]['items'][0]['items'][1] = [
                    'type'    => 'Label',
                    'caption' => ' ',
                    'visible' => $enabled
                ],
                $formData['elements'][1]['items'][0]['items'][2] = [
                    'type'     => 'OpenObjectButton',
                    'caption'  => 'ID ' . $id . ' bearbeiten',
                    'visible'  => $enabled,
                    'objectID' => $id
                ]
            ]
        ];
        $formData['elements'][1]['items'][1] = [
            'type'    => 'NumberSpinner',
            'name'    => 'AlarmSirenAcousticSignalSwitchingDelay',
            'caption' => 'Schaltverzögerung',
            'minimum' => 0,
            'suffix'  => 'Millisekunden'
        ];
        // Alarm siren optical signal
        $id = $this->ReadPropertyInteger('AlarmSirenOpticalSignal');
        $enabled = false;
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $enabled = true;
        }
        $formData['elements'][1]['items'][2] = [
            'type'  => 'RowLayout',
            'items' => [$formData['elements'][1]['items'][2]['items'][0] = [
                'type'    => 'SelectVariable',
                'name'    => 'AlarmSirenOpticalSignal',
                'caption' => 'Optisches Signal',
                'width'   => '600px',
            ],
                $formData['elements'][1]['items'][2]['items'][1] = [
                    'type'    => 'Label',
                    'caption' => ' ',
                    'visible' => $enabled
                ],
                $formData['elements'][1]['items'][2]['items'][2] = [
                    'type'     => 'OpenObjectButton',
                    'caption'  => 'ID ' . $id . ' bearbeiten',
                    'visible'  => $enabled,
                    'objectID' => $id
                ]
            ]
        ];
        $formData['elements'][1]['items'][3] = [
            'type'    => 'NumberSpinner',
            'name'    => 'AlarmSirenOpticalSignalSwitchingDelay',
            'caption' => 'Schaltverzögerung',
            'minimum' => 0,
            'suffix'  => 'Millisekunden'
        ];
        // Trigger variables
        $variables = json_decode($this->ReadPropertyString('TriggerVariables'));
        if (!empty($variables)) {
            foreach ($variables as $variable) {
                $rowColor = '#C0FFC0'; # light green
                $use = $variable->Use;
                if (!$use) {
                    $rowColor = '';
                }
                $id = $variable->ID;
                if ($id == 0 || @!IPS_ObjectExists($id)) {
                    $rowColor = '#FFC0C0'; # red
                }
                $formData['elements'][4]['items'][0]['values'][] = [
                    'Use'           => $use,
                    'ID'            => $id,
                    'TriggerType'   => $variable->TriggerType,
                    'TriggerValue'  => $variable->TriggerValue,
                    'TriggerAction' => $variable->TriggerAction,
                    'rowColor'      => $rowColor];
            }
        }
        // Alarm protocol
        $id = $this->ReadPropertyInteger('AlarmProtocol');
        $enabled = false;
        if ($id != 0 && @IPS_ObjectExists($id)) {
            $enabled = true;
        }
        $formData['elements'][6]['items'][0] = [
            'type'  => 'RowLayout',
            'items' => [$formData['elements'][6]['items'][0]['items'][0] = [
                'type'     => 'SelectModule',
                'name'     => 'AlarmProtocol',
                'caption'  => 'Alarmprotokoll',
                'moduleID' => '{33EF9DF1-C8D7-01E7-F168-0A1927F1C61F}',
                'width'    => '600px',
            ],
                $formData['elements'][6]['items'][0]['items'][1] = [
                    'type'    => 'Label',
                    'caption' => ' ',
                    'visible' => $enabled
                ],
                $formData['elements'][6]['items'][0]['items'][2] = [
                    'type'     => 'OpenObjectButton',
                    'caption'  => 'ID ' . $id . ' konfigurieren',
                    'visible'  => $enabled,
                    'objectID' => $id
                ]
            ]
        ];
        // Registered messages
        $messages = $this->GetMessageList();
        foreach ($messages as $senderID => $messageID) {
            $senderName = 'Objekt #' . $senderID . ' existiert nicht';
            $rowColor = '#FFC0C0'; # red
            if (@IPS_ObjectExists($senderID)) {
                $senderName = IPS_GetName($senderID);
                $rowColor = '#C0FFC0'; # light green
            }
            switch ($messageID) {
                case [10001]:
                    $messageDescription = 'IPS_KERNELSTARTED';
                    break;

                case [10603]:
                    $messageDescription = 'VM_UPDATE';
                    break;

                default:
                    $messageDescription = 'keine Bezeichnung';
            }
            $formData['actions'][1]['items'][0]['values'][] = [
                'SenderID'           => $senderID,
                'SenderName'         => $senderName,
                'MessageID'          => $messageID,
                'MessageDescription' => $messageDescription,
                'rowColor'           => $rowColor];
        }
        // Status
        $formData['status'][0] = [
            'code'    => 101,
            'icon'    => 'active',
            'caption' => 'Alarmsirene wird erstellt',
        ];
        $formData['status'][1] = [
            'code'    => 102,
            'icon'    => 'active',
            'caption' => 'Alarmsirene ist aktiv (ID ' . $this->InstanceID . ')',
        ];
        $formData['status'][2] = [
            'code'    => 103,
            'icon'    => 'active',
            'caption' => 'Alarmsirene wird gelöscht (ID ' . $this->InstanceID . ')',
        ];
        $formData['status'][3] = [
            'code'    => 104,
            'icon'    => 'inactive',
            'caption' => 'Alarmsirene ist inaktiv (ID ' . $this->InstanceID . ')',
        ];
        $formData['status'][4] = [
            'code'    => 200,
            'icon'    => 'inactive',
            'caption' => 'Es ist Fehler aufgetreten, weitere Informationen unter Meldungen, im Log oder Debug! (ID ' . $this->InstanceID . ')',
        ];
        return json_encode($formData);
    }

    public function ReloadConfiguration()
    {
        $this->ReloadForm();
    }

    public function EnableTriggerVariableConfigurationButton(int $ObjectID): void
    {
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'caption', 'Variable ' . $ObjectID . ' Bearbeiten');
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'visible', true);
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'enabled', true);
        $this->UpdateFormField('TriggerVariableConfigurationButton', 'objectID', $ObjectID);
    }

    public function ShowVariableDetails(int $VariableID): void
    {
        if ($VariableID == 0 || !@IPS_ObjectExists($VariableID)) {
            return;
        }
        if ($VariableID != 0) {
            // Variable
            echo 'ID: ' . $VariableID . "\n";
            echo 'Name: ' . IPS_GetName($VariableID) . "\n";
            $variable = IPS_GetVariable($VariableID);
            if (!empty($variable)) {
                $variableType = $variable['VariableType'];
                switch ($variableType) {
                    case 0:
                        $variableTypeName = 'Boolean';
                        break;

                    case 1:
                        $variableTypeName = 'Integer';
                        break;

                    case 2:
                        $variableTypeName = 'Float';
                        break;

                    case 3:
                        $variableTypeName = 'String';
                        break;

                    default:
                        $variableTypeName = 'Unbekannt';
                }
                echo 'Variablentyp: ' . $variableTypeName . "\n";
            }
            // Profile
            $profile = @IPS_GetVariableProfile($variable['VariableProfile']);
            if (empty($profile)) {
                $profile = @IPS_GetVariableProfile($variable['VariableCustomProfile']);
            }
            if (!empty($profile)) {
                $profileType = $variable['VariableType'];
                switch ($profileType) {
                    case 0:
                        $profileTypeName = 'Boolean';
                        break;

                    case 1:
                        $profileTypeName = 'Integer';
                        break;

                    case 2:
                        $profileTypeName = 'Float';
                        break;

                    case 3:
                        $profileTypeName = 'String';
                        break;

                    default:
                        $profileTypeName = 'Unbekannt';
                }
                echo 'Profilname: ' . $profile['ProfileName'] . "\n";
                echo 'Profiltyp: ' . $profileTypeName . "\n\n";
            }
            if (!empty($variable)) {
                echo "\nVariable:\n";
                print_r($variable);
            }
            if (!empty($profile)) {
                echo "\nVariablenprofil:\n";
                print_r($profile);
            }
        }
    }

    #################### Request Action

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'AlarmSiren':
                $this->ToggleAlarmSiren($Value);
                break;

            case 'ResetSignallingAmount':
                $this->ResetSignallingAmount();
                break;

            case 'MuteMode':
                $this->ToggleMuteMode($Value);
                break;

        }
    }

    #################### Private

    private function KernelReady(): void
    {
        $this->ApplyChanges();
    }

    private function ValidateConfiguration(): bool
    {
        $result = true;
        $status = 102;
        // Check pre and main alarm
        if ($this->ReadPropertyBoolean('UsePreAlarm') && $this->ReadPropertyBoolean('UseMainAlarm')) {
            if ($this->ReadPropertyInteger('MainAlarmSignallingDelay') <= $this->ReadPropertyInteger('PreAlarmDuration')) {
                $result = false;
                $status = 200;
                $text = 'Abbruch, die Einschaltverzögerung des Hauptalarms ist zu gering!';
                $this->SendDebug(__FUNCTION__, $text, 0);
                $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
            }
        }
        // Maintenance mode
        $maintenance = $this->CheckMaintenanceMode();
        if ($maintenance) {
            $result = false;
            $status = 104;
        }
        IPS_SetDisabled($this->InstanceID, $maintenance);
        $this->SetStatus($status);
        return $result;
    }

    private function CheckMaintenanceMode(): bool
    {
        $result = $this->ReadPropertyBoolean('MaintenanceMode');
        if ($result) {
            $text = 'Abbruch, der Wartungsmodus ist aktiv!';
            $this->SendDebug(__FUNCTION__, $text, 0);
            $this->LogMessage('ID ' . $this->InstanceID . ', ' . __FUNCTION__ . ', ' . $text, KL_WARNING);
        }
        return $result;
    }
}