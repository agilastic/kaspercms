<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Backend\Tests\Functional\Controller\Wizard;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Controller\Wizard\SuggestWizardController;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SuggestWizardTableAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['frontend'];

    public static function suggestDataProvider(): iterable
    {
        // [backend user, table, field, search, labels that MUST appear, labels that MUST NOT appear]
        yield 'editor w/o fe_users read access, allowed=* field' => [2, 'sys_category', 'items', 'secret', [], ['secret-customer']];
        yield 'editor w/o fe_groups read access, foreign_table field' => [2, 'tt_content', 'fe_group', 'secret', [], ['secret-group']];
        yield 'control: admin sees fe_users' => [1, 'sys_category', 'items', 'secret', ['secret-customer-1'], []];
        yield 'control: editor with fe_users read access sees fe_users' => [3, 'sys_category', 'items', 'secret', ['secret-customer-1'], []];
        yield 'control: records on pages without access never shown' => [3, 'sys_category', 'items', 'invisible', [], ['invisible-customer']];
    }

    #[Test]
    #[DataProvider('suggestDataProvider')]
    public function suggestRespectsTableReadPermissions(int $userId, string $table, string $field, string $search, array $mustContain, array $mustNotContain): void
    {
        $this->importCSVDataSet(__DIR__ . '/Fixtures/SuggestWizardTableAccess.csv');
        $backendUser = $this->setUpBackendUser($userId);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $request = (new ServerRequest('https://example.com/typo3/', 'POST'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withParsedBody(['value' => $search, 'tableName' => $table, 'fieldName' => $field, 'uid' => 0, 'pid' => 1, 'recordTypeValue' => $table === 'tt_content' ? 'text' : '1']);
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $body = (string)GeneralUtility::makeInstance(SuggestWizardController::class)->searchAction($request)->getBody();
        foreach ($mustContain as $label) {
            self::assertStringContainsString($label, $body);
        }
        foreach ($mustNotContain as $label) {
            self::assertStringNotContainsString($label, $body);
        }
    }
}
