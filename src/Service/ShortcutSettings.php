<?php

namespace App\Service;

/** One binding vocabulary for validation, hints and keyboard dispatch. */
final class ShortcutSettings
{
    public const DEFAULTS = [
        'review.fixed' => 'ArrowLeft',
        'review.confirmed' => 'ArrowRight',
        'review.skip' => 'Enter',
        'review.back' => 'ArrowDown',
        'review.poc' => 'ArrowUp',
        'review.focus' => 'F',
        'nav.intake' => '',
        'nav.review' => '',
        'nav.inventory' => '',
        'nav.statistics' => '',
        'nav.export' => '',
        'nav.settings' => '',
    ];

    public const LABELS = [
        'review.fixed' => 'Als behoben bewerten',
        'review.confirmed' => 'Als verwundbar bewerten',
        'review.skip' => 'Überspringen',
        'review.back' => 'Zurück & Reset',
        'review.poc' => 'PoC öffnen',
        'review.focus' => 'Fokusmodus',
        'nav.intake' => 'Eingang',
        'nav.review' => 'Bewerten',
        'nav.inventory' => 'Bestand',
        'nav.statistics' => 'Statistiken',
        'nav.export' => 'Export',
        'nav.settings' => 'Einstellungen',
    ];

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** @return array<string, string> */
    public function bindings(): array
    {
        try {
            $stored = json_decode($this->settings->getString('ui.shortcuts', '{}'), true, 16, JSON_THROW_ON_ERROR);
            return self::validate($stored);
        } catch (\InvalidArgumentException|\JsonException) {
            return self::DEFAULTS;
        }
    }

    /** @return array<string, string> */
    public static function validate(mixed $values): array
    {
        if (!is_array($values) || array_diff_key($values, self::DEFAULTS) !== []) {
            throw new \InvalidArgumentException('Ungültige Tastenkürzel.');
        }
        $bindings = self::DEFAULTS;
        $used = [];
        foreach ($bindings as $action => $default) {
            $value = array_key_exists($action, $values) ? $values[$action] : $default;
            if (!is_string($value)) throw new \InvalidArgumentException('Ungültige Tastenkürzel.');
            $value = self::normalize($value);
            if ($value !== '' && str_starts_with($action, 'nav.') && !preg_match('/(?:Ctrl|Alt|Meta)\+/', $value)) {
                throw new \InvalidArgumentException('Seitenwechsel benötigen eine Tastenkombination mit Ctrl, Alt oder Meta.');
            }
            if ($value !== '' && isset($used[$value])) {
                throw new \InvalidArgumentException('Jedes Tastenkürzel darf nur einmal vergeben werden.');
            }
            if ($value !== '') $used[$value] = true;
            $bindings[$action] = $value;
        }
        return $bindings;
    }

    public static function normalize(string $value): string
    {
        $value = trim($value);
        if ($value === '') return '';
        if (strlen($value) > 64) throw new \InvalidArgumentException('Ungültige Tastenkürzel.');
        $parts = explode('+', $value);
        $key = array_pop($parts);
        if (strlen($key) === 1) $key = strtoupper($key);
        if (!preg_match('/^(?:[A-Z0-9]|ArrowLeft|ArrowRight|ArrowUp|ArrowDown|Enter|Space)$/D', $key)) {
            throw new \InvalidArgumentException('Verwende Buchstaben, Ziffern, Pfeiltasten, Enter oder Space mit optionalen Modifikatortasten.');
        }
        if (count($parts) !== count(array_unique($parts)) || array_diff($parts, ['Ctrl', 'Alt', 'Shift', 'Meta']) !== []) {
            throw new \InvalidArgumentException('Ungültige Tastenkürzel.');
        }
        $modifiers = array_values(array_intersect(['Ctrl', 'Alt', 'Shift', 'Meta'], $parts));
        $binding = implode('+', [...$modifiers, $key]);
        // AltGr is text entry on many keyboards, never a workspace command.
        if (in_array('Ctrl', $modifiers, true) && in_array('Alt', $modifiers, true)) {
            throw new \InvalidArgumentException('Ctrl+Alt ist für AltGr-Texteingaben reserviert.');
        }
        if (in_array('Ctrl', $modifiers, true) || in_array('Meta', $modifiers, true)) {
            if (!in_array('Shift', $modifiers, true) || in_array($key, ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z'], true)) {
                throw new \InvalidArgumentException('Diese Tastenkombination ist für Browser oder Texteingabe reserviert.');
            }
        }
        if (in_array('Alt', $modifiers, true) && !in_array('Shift', $modifiers, true)
            && in_array($key, ['ArrowLeft', 'ArrowRight', 'Enter', 'D', 'F', 'Space', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], true)) {
            throw new \InvalidArgumentException('Diese Tastenkombination ist für Browser oder Texteingabe reserviert.');
        }
        return $binding;
    }
}
