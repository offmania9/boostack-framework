<?php

namespace Boostack\Models;

/**
 * Boostack: Language.php
 * ========================================================================
 * Copyright 2014-2025 Spagnolo Stefano
 * Licensed under MIT (https://github.com/offmania9/Boostack/blob/master/LICENSE)
 * ========================================================================
 * @author Spagnolo Stefano <s.spagnolo@hotmail.it>
 * @version 6.0
 */

class Language
{
    /**
     * Prevents direct instantiation of Language.
     */
    private function __construct() {}

    protected static $translatedLabels;
    /**
     * Initialize the language settings.
     */
    public static function init(): void
    {
        // Find the language based on configuration
        $language = self::findLanguage();

        // Get translated labels for the language
        $translatedLabels = Language::getLabelsFromLanguage($language);

        // Set session language if session is enabled
        if (Config::get('session_on')) {
            Language::setSessionLanguage($language);
        }

        // Set translated labels
        self::$translatedLabels = $translatedLabels;
    }

    /**
     * Get the label for the given key.
     *
     * @param string $key The key for the label.
     * @return string The translated label.
     */
    public static function getLabel($key)
    {
        if (is_array(self::$translatedLabels)) {
            $keys = explode(".", $key);
            $tempArray = self::$translatedLabels;
            foreach ($keys as $key) {
                if (!empty($tempArray[$key])) {
                    $tempArray = $tempArray[$key];
                } else {
                    return "";
                }
            }
            return $tempArray;
        }
        return "";
    }

    /**
     * Find the language based on configuration and request.
     *
     * @return string The language found.
     */
    private static function findLanguage()
    {
        $defaultLanguage = Config::get("language_default");
        $language = null;

        // Check if the default language should be forced
        if (Config::get("language_force_default")) {
            $language = $defaultLanguage;
        } elseif (Request::hasQueryParam("lang")) {
            $language = Request::getQueryParam("lang");
        } elseif (Config::get("session_on") && \Boostack\Models\Session\Session::get("SESS_LANGUAGE") !== "") {
            // if is set in the user session
            $language = \Boostack\Models\Session\Session::get("SESS_LANGUAGE");
        } elseif (Request::hasServerParam('HTTP_ACCEPT_LANGUAGE')) {
            // if isn't set in the user session, fetch it from browser
            $language = explode(',', Request::getServerParam('HTTP_ACCEPT_LANGUAGE'));
            $language = strtolower(substr(rtrim($language[0]), 0, 2));
        }

        if (in_array($language, Config::get("enabled_languages"))) {
            return $language;
        }

        return $defaultLanguage;
    }

    /**
     * Set the session language.
     *
     * @param string $lang The language to set in session.
     * @throws \Exception_Misconfiguration If session or database is not enabled.
     */
    private static function setSessionLanguage($lang): void
    {
        Config::constraint("session_on");
        Config::constraint("database_on");
        \Boostack\Models\Session\Session::set("SESS_LANGUAGE", $lang);
    }

    /**
     * Get translated labels from language file.
     *
     * @param string $lang The language for which to get labels.
     * @return array The translated labels.
     * @throws \Exception If language file not found.
     */
    private static function getLabelsFromLanguage(string $lang)
    {
        $baseFilePath = self::buildLanguageFilePath($lang);
        if (!is_file($baseFilePath)) {
            throw new \Exception("Language file " . $baseFilePath . " not found");
        }

        $variantCode = self::resolveLanguageVariantCode();
        if ($variantCode !== '') {
            $variantFilePath = self::buildLanguageFilePath($lang . '.' . $variantCode);
            if (is_file($variantFilePath)) {
                return self::decodeLabelsFromFile($variantFilePath);
            }
        }

        return self::decodeLabelsFromFile($baseFilePath);
    }

    /**
     * Build full language file path from language code.
     */
    private static function buildLanguageFilePath(string $languageCode): string
    {
        return $_SERVER['DOCUMENT_ROOT'] . "/" . Config::get("language_path") . $languageCode . Config::get("language_file_extension");
    }

    /**
     * Decode language labels JSON from file.
     *
     * @return array<string, mixed>
     */
    private static function decodeLabelsFromFile(string $filePath): array
    {
        $jsonFileContent = file_get_contents($filePath);
        $decoded = json_decode((string)$jsonFileContent, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Resolve optional language variant code (e.g. license/company profile).
     */
    private static function resolveLanguageVariantCode(): string
    {
        $variantCode = trim((string)Config::get("language_variant_code"));
        if ($variantCode === '') {
            return '';
        }

        $normalized = strtolower($variantCode);
        $sanitized = preg_replace('/[^a-z0-9_-]/', '', $normalized);
        return is_string($sanitized) ? $sanitized : '';
    }
}
