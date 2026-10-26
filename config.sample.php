<?php
/**
 * Divotraily – tajné nastavenia servera.
 * 1. Skopíruj tento súbor ako config.php (vedľa api.php).
 * 2. Vyplň vlastné heslá a kľúč. config.php NIKDY nedávaj do gitu (je v .gitignore).
 */
return [
    // heslá rolí (aspoň 8 znakov, každé iné). Pozor: nepoužívaj heslá, ktoré sú v index.html (náhľad v Claude).
    'ucty' => [
        'brigada'  => 'SEM-DAJ-HESLO-PRE-BRIGADU',
        'admin'    => 'SEM-DAJ-HESLO-PRE-ADMINA',
        'designer' => 'SEM-DAJ-HESLO-PRE-DESIGNERA',
    ],
    // dlhý náhodný reťazec (aspoň 32 znakov) – na podpis prihlásenia; po zmene sa všetci musia prihlásiť znova
    'tajny_kluc' => 'SEM-DAJ-DLHY-NAHODNY-RETAZEC-ASPON-32-ZNAKOV',
    // schránka na doméne webu na odosielanie e-mailov (prihlášky, dopyty na merch, tričká)
    'smtp_user' => 'web@divotraily.sk',
    'smtp_pass' => '',
];
