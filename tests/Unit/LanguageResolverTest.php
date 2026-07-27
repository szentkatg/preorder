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
}
