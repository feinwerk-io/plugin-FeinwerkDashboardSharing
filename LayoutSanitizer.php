<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

namespace Piwik\Plugins\FeinwerkDashboardSharing;

/**
 * Cleans an owner's dashboard layout before it becomes a subscriber's copy. The subscriber's browser loads every widget
 * of the copy with the parameters stored in the layout, in the subscriber's session. So only well-formed widgets are
 * kept, and parameters that would change who makes the request, for which website, period or segment, or what is
 * requested are not taken over. Which widgets the subscriber may load, and every request itself, Matomo still checks.
 */
class LayoutSanitizer
{
    const DEFAULT_COLUMN_LAYOUT = '33-33-33';
    const MAX_VALUE_LENGTH = 255;
    /** Lists (e.g. the metrics and rows picked in an evolution graph) may hold this many plain values. */
    const MAX_LIST_LENGTH = 50;

    /** Widget ids Matomo 6 refuses because they name JavaScript object internals (Dashboard::RESERVED_WIDGET_UNIQUE_IDS). */
    const RESERVED_UNIQUE_IDS = array(
        '__proto__', 'constructor', 'prototype', '__definegetter__', '__definesetter__', '__lookupgetter__',
        '__lookupsetter__', 'hasownproperty', 'isprototypeof', 'propertyisenumerable', 'tolocalestring', 'tostring',
        'valueof',
    );

    /** Widget parameters never taken over from the owner (compared case-insensitively). */
    const DROPPED_PARAMETERS = array(
        'module', 'action', // set again from the validated widget identity
        'token_auth', 'force_api_session',
        'idsite', 'idsites', 'period', 'date', 'segment', 'comparesegments', 'compareperiods', 'comparedates',
        'format', 'method', 'callback', 'jsoncallback', 'url', 'moduletowidgetize', 'actiontowidgetize',
        'widget', 'disablelink', // set by Matomo's dashboard when it loads a widget
    );

    /**
     * @param mixed $layout the layout as stored by Matomo's Dashboard plugin (JSON, possibly HTML-encoded)
     * @return string the cleaned layout as JSON, in Matomo's current format ({config, columns})
     */
    public function sanitize($layout): string
    {
        $config = array('layout' => self::DEFAULT_COLUMN_LAYOUT);
        $columns = array();

        $decoded = $this->decode($layout);
        if (is_array($decoded)) {
            $rawColumns = $decoded; // the old format stores the columns only
            if (array_key_exists('columns', $decoded) || array_key_exists('config', $decoded)) {
                $rawColumns = isset($decoded['columns']) ? $decoded['columns'] : array();
                $columnLayout = isset($decoded['config']['layout']) ? $decoded['config']['layout'] : null;
                if (is_string($columnLayout) && preg_match('/^\d{1,3}(-\d{1,3}){0,3}$/', $columnLayout)) {
                    $config['layout'] = $columnLayout;
                }
            }
            foreach (is_array($rawColumns) ? $rawColumns : array() as $column) {
                $widgets = array();
                foreach (is_array($column) ? $column : array() as $widget) {
                    $clean = $this->widget($widget);
                    if ($clean !== null) {
                        $widgets[] = $clean;
                    }
                }
                $columns[] = $widgets;
            }
        }

        return json_encode(array('config' => $config, 'columns' => $columns));
    }

    /** Reads a stored layout the way Matomo's Dashboard::decodeLayout() does. */
    private function decode($layout)
    {
        if (!is_string($layout)) {
            return null;
        }
        $layout = html_entity_decode($layout, ENT_COMPAT | ENT_HTML401, 'UTF-8');
        $layout = str_replace('\\"', '"', $layout);
        $layout = str_replace("\n", '', $layout);

        return json_decode($layout, true);
    }

    /** @return array|null the cleaned widget, or null when it is not a well-formed widget */
    private function widget($widget)
    {
        if (!is_array($widget) || !isset($widget['parameters']) || !is_array($widget['parameters'])) {
            return null;
        }
        $parameters = $widget['parameters'];
        $module = isset($parameters['module']) ? $parameters['module'] : null;
        $action = isset($parameters['action']) ? $parameters['action'] : null;
        $uniqueId = isset($widget['uniqueId']) && is_string($widget['uniqueId'])
            ? preg_replace('/[^a-zA-Z0-9_.+-]/', '', $widget['uniqueId'])
            : '';
        if (!$this->isIdentifier($module) || !$this->isIdentifier($action) || $uniqueId === ''
            || in_array(strtolower($uniqueId), self::RESERVED_UNIQUE_IDS, true)
        ) {
            return null;
        }

        $clean = array('module' => $module, 'action' => $action);
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}$/', $key)
                || in_array(strtolower($key), self::DROPPED_PARAMETERS, true)
            ) {
                continue;
            }
            if ($this->isPlainValue($value) || $this->isListOfPlainValues($value)) {
                $clean[$key] = $value;
            }
        }

        return array('uniqueId' => $uniqueId, 'parameters' => $clean, 'isHidden' => !empty($widget['isHidden']));
    }

    private function isPlainValue($value): bool
    {
        return is_string($value) ? strlen($value) <= self::MAX_VALUE_LENGTH : (is_int($value) || is_float($value) || is_bool($value));
    }

    /** A flat list such as ['nb_visits', 'nb_actions'], as Matomo's series picker stores it; never a nested structure. */
    private function isListOfPlainValues($value): bool
    {
        if (!is_array($value) || count($value) > self::MAX_LIST_LENGTH || array_keys($value) !== range(0, count($value) - 1)) {
            return false;
        }
        foreach ($value as $item) {
            if (is_bool($item) || !$this->isPlainValue($item)) {
                return false;
            }
        }
        return true;
    }

    private function isIdentifier($value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,99}$/', $value) === 1;
    }
}
