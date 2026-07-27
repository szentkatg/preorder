<?php

namespace Tests\Unit;

use App\Models\Language;
use App\Services\LanguageResolver;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

class LanguageResolverTest extends TestCase
{
    public function test_partner_language_session_takes_precedence(): void
    {
        $hungarian = new Language([
            'code' => 'HU',
            'name' => 'Magyar',
            'active' => true,
        ]);
        $hungarian->id = 1;

        $english = new Language([
            'code' => 'EN',
            'name' => 'English',
            'active' => true,
        ]);
        $english->id = 2;

        $session = new Store('test', new ArraySessionHandler(120));
        $session->put('language_id', $hungarian->id);
        $session->put('partner.language_id', $english->id);

        $request = Request::create('/');
        $request->setLaravelSession($session);

        $resolver = new class($request, [$hungarian, $english]) extends LanguageResolver
        {
            public function __construct(
                Request $request,
                private readonly array $languages,
            ) {
                parent::__construct($request);
            }

            protected function findActiveLanguageById(int $languageId): ?Language
            {
                foreach ($this->languages as $language) {
                    if ((int) $language->id === $languageId) {
                        return $language;
                    }
                }

                return null;
            }
        };

        $language = $resolver->resolve();

        $this->assertSame($english->id, $language?->id);
    }

    public function test_supported_browser_language_is_selected(): void
    {
        [$hungarian, $english] = $this->languages();

        $request = Request::create(
            uri: '/',
            server: ['HTTP_ACCEPT_LANGUAGE' => 'en-US,en;q=0.9,hu;q=0.8'],
        );

        $language = $this->resolver(
            $request,
            [$hungarian, $english],
        )->resolve(allowedLanguageCodes: ['HU', 'EN']);

        $this->assertSame($english->id, $language?->id);
    }

    public function test_unsupported_browser_language_falls_back_to_hungarian(): void
    {
        [$hungarian, $english] = $this->languages();

        $request = Request::create(
            uri: '/',
            server: ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9'],
        );

        $language = $this->resolver(
            $request,
            [$hungarian, $english],
        )->resolve(allowedLanguageCodes: ['HU', 'EN']);

        $this->assertSame($hungarian->id, $language?->id);
    }

    public function test_remembered_cookie_language_takes_precedence_over_browser(): void
    {
        [$hungarian, $english] = $this->languages();

        $request = Request::create(
            uri: '/',
            server: ['HTTP_ACCEPT_LANGUAGE' => 'hu-HU,hu;q=0.9'],
        );
        $request->cookies->set('partner_locale', 'en');

        $language = $this->resolver(
            $request,
            [$hungarian, $english],
        )->resolve(allowedLanguageCodes: ['HU', 'EN']);

        $this->assertSame($english->id, $language?->id);
    }

    public function test_explicit_language_selection_is_resolved(): void
    {
        [$hungarian, $english] = $this->languages();

        $language = $this->resolver(
            Request::create('/'),
            [$hungarian, $english],
        )->resolve(
            languageId: $english->id,
            allowedLanguageCodes: ['HU', 'EN'],
        );

        $this->assertSame($english->id, $language?->id);
    }

    /**
     * @return array{Language, Language}
     */
    private function languages(): array
    {
        $hungarian = new Language([
            'code' => 'HU',
            'name' => 'Magyar',
            'active' => true,
        ]);
        $hungarian->id = 1;

        $english = new Language([
            'code' => 'EN',
            'name' => 'English',
            'active' => true,
        ]);
        $english->id = 2;

        return [$hungarian, $english];
    }

    /**
     * @param  array<int, Language>  $languages
     */
    private function resolver(
        Request $request,
        array $languages,
    ): LanguageResolver {
        return new class($request, $languages) extends LanguageResolver
        {
            public function __construct(
                Request $request,
                private readonly array $languages,
            ) {
                parent::__construct($request);
            }

            protected function findActiveLanguageById(int $languageId): ?Language
            {
                foreach ($this->languages as $language) {
                    if ((int) $language->id === $languageId) {
                        return $language;
                    }
                }

                return null;
            }

            protected function findActiveLanguageByCode(string $languageCode): ?Language
            {
                foreach ($this->languages as $language) {
                    if (strtoupper((string) $language->code) === $languageCode) {
                        return $language;
                    }
                }

                return null;
            }

            protected function findFirstActiveLanguage(
                ?array $allowedLanguageCodes,
            ): ?Language {
                foreach ($this->languages as $language) {
                    if (
                        $allowedLanguageCodes === null
                        || in_array(
                            strtoupper((string) $language->code),
                            $allowedLanguageCodes,
                            true,
                        )
                    ) {
                        return $language;
                    }
                }

                return null;
            }
        };
    }
}
