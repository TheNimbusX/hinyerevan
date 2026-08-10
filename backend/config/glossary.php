<?php

/*
|--------------------------------------------------------------------------
| Protected proper nouns
|--------------------------------------------------------------------------
|
| Machine translation renders Armenian proper nouns literally: the surname
| Գառզու (the painter Garzou) becomes "ягнёнок", Քոչարյան can turn into a
| common noun, and so on. Terms listed here are hidden from the translation
| engine and restored afterwards using the spelling given below.
|
| The key is the Armenian stem WITHOUT case endings — declined forms such as
| Գառզուն / Գառզուին are matched automatically.
|
| Bump 'version' after editing so cached translations are refreshed.
|
*/

return [

    'version' => 1,

    'terms' => [
        // Painters, public figures
        'Գառզու' => ['ru' => 'Гарзу', 'en' => 'Garzou'],
        'Սարյան' => ['ru' => 'Сарьян', 'en' => 'Saryan'],
        'Փարաջանով' => ['ru' => 'Параджанов', 'en' => 'Parajanov'],
        'Խաչատրյան' => ['ru' => 'Хачатрян', 'en' => 'Khachatryan'],
        'Թամանյան' => ['ru' => 'Таманян', 'en' => 'Tamanyan'],
        'Իսահակյան' => ['ru' => 'Исаакян', 'en' => 'Isahakyan'],
        'Թումանյան' => ['ru' => 'Туманян', 'en' => 'Tumanyan'],
        'Բաղրամյան' => ['ru' => 'Баграмян', 'en' => 'Baghramyan'],
        'Մաշտոց' => ['ru' => 'Маштоц', 'en' => 'Mashtots'],
        'Աբովյան' => ['ru' => 'Абовян', 'en' => 'Abovyan'],
        'Նալբանդյան' => ['ru' => 'Налбандян', 'en' => 'Nalbandyan'],
        'Չարենց' => ['ru' => 'Чаренц', 'en' => 'Charents'],
        'Շահումյան' => ['ru' => 'Шаумян', 'en' => 'Shahumyan'],
        'Մյասնիկյան' => ['ru' => 'Мясникян', 'en' => 'Myasnikyan'],
        'Կոմիտաս' => ['ru' => 'Комитас', 'en' => 'Komitas'],
    ],

];
