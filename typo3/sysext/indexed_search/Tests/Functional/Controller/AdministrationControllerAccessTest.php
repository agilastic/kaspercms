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

namespace TYPO3\CMS\IndexedSearch\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Extbase\Core\Bootstrap;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The indexing module shows and removes index data only for pages the backend
 * user may read. Admins are not restricted.
 */
final class AdministrationControllerAccessTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['indexed_search'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/Fixtures/AdministrationControllerAccess.csv');
    }

    #[Test]
    public function statisticDetailsShowsWordsOfAccessiblePage(): void
    {
        self::assertStringContainsString('allowedword', $this->dispatch(10, 'statisticDetails', 3, ['pageHash' => 1003]));
    }

    #[Test]
    public function statisticDetailsDoesNotShowWordsOfInaccessiblePage(): void
    {
        self::assertStringNotContainsString('restrictedword', $this->dispatch(10, 'statisticDetails', 1, ['pageHash' => 1002]));
    }

    #[Test]
    public function statisticDetailsShowsWordsOfAnyPageToAdmins(): void
    {
        self::assertStringContainsString('restrictedword', $this->dispatch(1, 'statisticDetails', 1, ['pageHash' => 1002]));
    }

    #[Test]
    public function wordDetailOnlyListsAccessiblePages(): void
    {
        $result = $this->dispatch(10, 'wordDetail', 1, ['wordHash' => 503]);
        self::assertStringContainsString('>1003</a>', $result);
        self::assertStringNotContainsString('>1002</a>', $result);
    }

    #[Test]
    public function wordDetailListsAllPagesToAdmins(): void
    {
        $result = $this->dispatch(1, 'wordDetail', 1, ['wordHash' => 503]);
        self::assertStringContainsString('>1003</a>', $result);
        self::assertStringContainsString('>1002</a>', $result);
    }

    #[Test]
    public function pagesOnlyListsAccessiblePages(): void
    {
        $result = $this->dispatch(10, 'pages', 1);
        self::assertStringContainsString('Allowed page title', $result);
        self::assertStringNotContainsString('Restricted page title', $result);
    }

    #[Test]
    public function pagesListsAllPagesToAdmins(): void
    {
        $result = $this->dispatch(1, 'pages', 1);
        self::assertStringContainsString('Allowed page title', $result);
        self::assertStringContainsString('Restricted page title', $result);
    }

    #[Test]
    public function externalDocumentsOnlyListsDocumentsOfAccessiblePages(): void
    {
        $result = $this->dispatch(10, 'externalDocuments', 1);
        self::assertStringContainsString('Allowed document title', $result);
        self::assertStringNotContainsString('Restricted document title', $result);
    }

    #[Test]
    public function statisticDoesNotShowInaccessibleStartPage(): void
    {
        self::assertStringNotContainsString('Restricted page title', $this->dispatch(10, 'statistic', 2, ['depth' => 0]));
    }

    #[Test]
    public function statisticShowsAccessibleStartPage(): void
    {
        self::assertStringContainsString('Allowed page title', $this->dispatch(10, 'statistic', 3, ['depth' => 0]));
    }

    #[Test]
    public function deleteIndexedItemOnlyRemovesItemsOfAccessiblePages(): void
    {
        $this->dispatch(10, 'deleteIndexedItem', 1, ['itemId' => '1002,1003'], 'POST');
        self::assertSame([1002, 1004, 1005], $this->getPageHashes());
    }

    #[Test]
    public function deleteIndexedItemDoesNotRemoveAllItemsOfInaccessiblePage(): void
    {
        $this->dispatch(10, 'deleteIndexedItem', 2, ['itemId' => 'ALL', 'depth' => 0], 'POST');
        self::assertSame([1002, 1003, 1004, 1005], $this->getPageHashes());
    }

    #[Test]
    public function deleteIndexedItemRemovesItemsOfAnyPageForAdmins(): void
    {
        $this->dispatch(1, 'deleteIndexedItem', 1, ['itemId' => '1002'], 'POST');
        self::assertSame([1003, 1004, 1005], $this->getPageHashes());
    }

    private function dispatch(int $backendUserUid, string $action, int $pageId, array $arguments = [], string $method = 'GET'): string
    {
        $backendUser = $this->setUpBackendUser($backendUserUid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $module = $this->get(ModuleProvider::class)->getModule('web_IndexedSearchIsearch');
        $queryParams = ['id' => $pageId, 'controller' => 'Administration', 'action' => $action];
        if ($method === 'GET') {
            $queryParams = array_replace($queryParams, $arguments);
        }
        $request = (new ServerRequest('https://localhost/typo3/module/web/IndexedSearchIsearch?' . http_build_query($queryParams), $method))
            ->withQueryParams($queryParams)
            ->withParsedBody($method === 'POST' ? $arguments : null)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('module', $module)
            ->withAttribute('route', new Route('/module/web/IndexedSearchIsearch', [
                'module' => $module,
                'packageName' => 'typo3/cms-indexed-search',
                'controller' => 'Administration',
                'action' => $action,
            ]));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return (string)$this->get(Bootstrap::class)->handleBackendRequest($request)->getBody();
    }

    /**
     * @return list<int>
     */
    private function getPageHashes(): array
    {
        $rows = $this->get(ConnectionPool::class)->getConnectionForTable('index_phash')
            ->select(['phash'], 'index_phash', [], [], ['phash' => 'ASC'])
            ->fetchFirstColumn();
        return array_map('intval', $rows);
    }
}
