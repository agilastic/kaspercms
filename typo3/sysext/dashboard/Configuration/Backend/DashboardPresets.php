<?php

return [
    'default' => [
        'title' => 'LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:dashboard.default',
        'description' => 'LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:dashboard.default.description',
        'iconIdentifier' => 'content-dashboard',
        // KasperCMS: the "About TYPO3" and "Getting Started with TYPO3" widgets are not registered
        'defaultWidgets' => [],
        'showInWizard' => false,
    ],
    'empty' => [
        'title' => 'LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:dashboard.empty',
        'description' => 'LLL:EXT:dashboard/Resources/Private/Language/locallang.xlf:dashboard.empty.description',
        'iconIdentifier' => 'content-dashboard-empty',
        'defaultWidgets' => [],
        'showInWizard' => true,
    ],
];
