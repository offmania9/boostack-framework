<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    // Specifica le cartelle in cui applicare il refactoring
    $rectorConfig->paths([
        __DIR__ . '/src', // Modifica con il percorso corretto della tua cartella "src" o delle cartelle che vuoi analizzare
    ]);

    // Escludi cartelle o file specifici se necessario
    $rectorConfig->skip([
        __DIR__ . '/vendor',
        // __DIR__ . '/config',
        // __DIR__ . '/migrations',
        // __DIR__ . '/database',
        // __DIR__ . '/tests',
        // Puoi anche escludere file specifici:
        // __DIR__ . '/src/SomeOldLegacyFile.php',
    ]);

    // Applica i set di regole desiderati
    $rectorConfig->sets([
        SetList::PHP_84, // Mantieni le regole di migrazione allineate alla versione PHP minima supportata
        SetList::CODE_QUALITY,
        SetList::DEAD_CODE,
        SetList::NAMING,
        // Aggiungi altri set di regole in base alle tue esigenze:
        SetList::TYPE_DECLARATION,
        // SetList::EARLY_RETURN,
        //SetList::CODING_STYLE,
    ]);


    // Puoi anche aggiungere regole specifiche singolarmente se non vuoi usare un intero set
    // $rectorConfig->rules([
    //     \Rector\Strict\Rector\Empty_\EmptyStringToNullRector::class,
    // ]);

    // Configurazione aggiuntiva (opzionale)
    $rectorConfig->parallel(); // Abilita l'esecuzione parallela per progetti più grandi (richiede l'estensione pcntl)
    $rectorConfig->cacheDirectory(__DIR__ . '/var/cache/rector'); // Specifica una directory di cache personalizzata

    // Se hai bisogno di ignorare alcuni errori specifici di Rector
    // $rectorConfig->errors([
    //     'SomeRectorRule' => [
    //         __DIR__ . '/src/ problematico/File.php',
    //     ],
    // ]);
};
