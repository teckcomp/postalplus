<?php

/**
 * Postal+ — direitos próprios e aba "Postal+" no formulário de Perfil.
 *
 * @copyright Teckcomp
 * @license   GPLv3+
 */

namespace GlpiPlugin\Postalplus;

use CommonGLPI;
use Html;
use Profile;
use Session;

class PerfilDireitos extends CommonGLPI
{
    /** Objetos rastreados: ler / criar / editar / excluir. */
    public const RIGHT_OBJETO = 'plugin_postalplus_objeto';

    /** Configuração do plugin (credenciais CWS, regras, frequência). */
    public const RIGHT_CONFIG = 'plugin_postalplus_config';

    public static $rightname = 'profile';

    public static function getTypeName($nb = 0)
    {
        return 'Postal+';
    }

    /**
     * Direitos do plugin e o valor que perfis com "Configurar" (super-admin) recebem
     * na instalação. Os demais perfis nascem SEM direito.
     *
     * @return array<string,int>
     */
    public static function getDireitos(): array
    {
        return [
            self::RIGHT_OBJETO => READ | CREATE | UPDATE | DELETE, // 15
            self::RIGHT_CONFIG => READ | UPDATE,                   // 3
        ];
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('interface') === 'central') {
            return self::createTabEntry(self::getTypeName(), 0, $item::class, 'ti ti-truck-delivery');
        }

        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        if ($item instanceof Profile) {
            self::mostrarMatriz((int) $item->getID());
        }

        return true;
    }

    public static function mostrarMatriz(int $profiles_id): void
    {
        $profile = new Profile();
        if (!$profile->getFromDB($profiles_id)) {
            return;
        }

        $canedit = Session::haveRightsOr('profile', [CREATE, UPDATE, PURGE]);

        echo "<form method='post' action='" . htmlescape(Profile::getFormURL()) . "'>";

        $profile->displayRightsChoiceMatrix(
            [
                [
                    'label'  => 'Objetos rastreados',
                    'field'  => self::RIGHT_OBJETO,
                    'rights' => [
                        READ   => __('Read'),
                        CREATE => __('Create'),
                        UPDATE => __('Update'),
                        DELETE => __('Delete'),
                    ],
                ],
                [
                    'label'  => 'Configuração do Postal+',
                    'field'  => self::RIGHT_CONFIG,
                    'rights' => [
                        READ   => __('Read'),
                        UPDATE => __('Update'),
                    ],
                ],
            ],
            [
                'canedit'       => $canedit,
                'default_class' => 'tab_bg_2',
                'title'         => 'Postal+',
            ]
        );

        if ($canedit) {
            echo "<div class='text-center mt-2'>";
            echo Html::hidden('id', ['value' => $profiles_id]);
            echo Html::submit(_sx('button', 'Save'), ['name' => 'update', 'class' => 'btn btn-primary']);
            echo '</div>';
        }

        Html::closeForm();
    }
}
