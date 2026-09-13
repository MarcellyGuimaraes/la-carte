<?php

namespace Tests\Feature\Security;

use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\InteractsWithPanels;
use Tests\Concerns\WithTwoTenants;
use Tests\TestCase;

/**
 * Quem nem deveria entrar: anônimo nos painéis, dono no /plataforma, força
 * bruta no login.
 */
#[Group('security')]
class AuthenticationTest extends TestCase
{
    use InteractsWithPanels, WithTwoTenants;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTwoTenants();
    }

    /** @return array<string, array{string, string}> */
    public static function protectedPages(): array
    {
        return [
            'admin raiz' => ['/admin', '/admin/login'],
            'admin itens' => ['/admin/bar-do-tonho/items', '/admin/login'],
            'admin editar item' => ['/admin/bar-do-tonho/items/1/edit', '/admin/login'],
            'admin categorias' => ['/admin/bar-do-tonho/categories', '/admin/login'],
            'plataforma raiz' => ['/plataforma', '/plataforma/login'],
            'plataforma restaurantes' => ['/plataforma/tenants', '/plataforma/login'],
            'plataforma criar restaurante' => ['/plataforma/tenants/create', '/plataforma/login'],
        ];
    }

    #[DataProvider('protectedPages')]
    public function test_anonymous_is_sent_to_login_without_seeing_data(string $page, string $login): void
    {
        $this->get($page)
            ->assertRedirect($login)
            ->assertDontSee('bar-do-tonho item')
            ->assertDontSee('pizzaria-da-nona');
    }

    /** @return array<string, array{string}> */
    public static function platformPages(): array
    {
        return [
            'raiz' => ['/plataforma'],
            'lista' => ['/plataforma/tenants'],
            'criar' => ['/plataforma/tenants/create'],
            'editar' => ['/plataforma/tenants/2/edit'],
        ];
    }

    #[DataProvider('platformPages')]
    public function test_restaurant_owner_is_forbidden_in_every_platform_page(string $page): void
    {
        $this->actingAs($this->createUser($this->tonho))
            ->get($page)
            ->assertForbidden()
            ->assertDontSee('pizzaria-da-nona');
    }

    public function test_login_is_rate_limited_even_with_the_right_password_after_failures(): void
    {
        $owner = $this->createUser($this->tonho);
        Filament::setCurrentPanel('admin');

        /* 5 tentativas erradas esgotam o limite do Filament. */
        foreach (range(1, 5) as $_) {
            Livewire::test(Login::class)
                ->fillForm(['email' => $owner->email, 'password' => 'errada'])
                ->call('authenticate');
        }

        /* A 6ª, mesmo certa, é barrada: quem adivinha na força não chega a testar. */
        Livewire::test(Login::class)
            ->fillForm(['email' => $owner->email, 'password' => 'segredo123'])
            ->call('authenticate');

        $this->assertGuest();
    }

    public function test_login_with_the_right_password_works_before_the_limit(): void
    {
        /* Controle do teste acima: sem ele, "nunca loga" também passaria. */
        $owner = $this->createUser($this->tonho);
        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => $owner->email, 'password' => 'segredo123'])
            ->call('authenticate');

        $this->assertAuthenticatedAs($owner);
    }
}
