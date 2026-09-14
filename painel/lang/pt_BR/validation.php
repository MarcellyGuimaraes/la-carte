<?php

/*
 * Mensagens de validação em português, para quem usa os painéis.
 *
 * O Laravel só traz inglês, e o Filament traduz os textos dele, não as regras
 * de validação: sem este arquivo, o dono lia "The Preço field must not be
 * greater than 99999.99.". Só as regras que os formulários usam; o que
 * faltar cai no inglês (fallback_locale), e ErrorMessagesTest pega.
 *
 * :attribute vira o label do campo no Filament ("Preço", "Foto").
 */
return [
    'array' => 'O campo :attribute precisa ser uma lista.',
    'boolean' => 'O campo :attribute precisa ser verdadeiro ou falso.',
    'confirmed' => 'A confirmação de :attribute não confere.',
    'dimensions' => 'O campo :attribute precisa ser uma imagem válida.',
    'email' => 'O campo :attribute deve ser um endereço de e-mail válido.',
    'exists' => 'O valor selecionado em :attribute é inválido.',
    'file' => 'O campo :attribute precisa ser um arquivo.',
    'image' => 'O campo :attribute precisa ser uma imagem.',
    'in' => 'O valor selecionado em :attribute é inválido.',
    'integer' => 'O campo :attribute deve ser um número inteiro.',
    'max' => [
        'array' => 'O campo :attribute não pode ter mais que :max itens.',
        'file' => 'O campo :attribute não pode ser maior que :max kilobytes.',
        'numeric' => 'O campo :attribute não pode ser maior que :max.',
        'string' => 'O campo :attribute não pode ter mais que :max caracteres.',
    ],
    'mimetypes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'mimes' => 'O campo :attribute deve ser um arquivo do tipo: :values.',
    'min' => [
        'array' => 'O campo :attribute precisa ter pelo menos :min itens.',
        'file' => 'O campo :attribute precisa ter pelo menos :min kilobytes.',
        'numeric' => 'O campo :attribute deve ser pelo menos :min.',
        'string' => 'O campo :attribute deve ter pelo menos :min caracteres.',
    ],
    'numeric' => 'O campo :attribute deve ser um número.',
    'regex' => 'O formato de :attribute é inválido.',
    'required' => 'O campo :attribute é obrigatório.',
    'string' => 'O campo :attribute precisa ser um texto.',
    'unique' => 'Este valor de :attribute já está em uso.',
    'uploaded' => 'Não foi possível enviar o arquivo em :attribute. Tente de novo.',

    'custom' => [],
    'attributes' => [],
];
