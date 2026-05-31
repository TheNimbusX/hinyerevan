// Modern, localised User Agreement for HinYerevan.com.
//
// The legacy site stored the agreement as a single Armenian HTML blob exported
// from Microsoft Word. Page bodies are not translated by the backend, so we
// render the agreement on the client instead, picking the document that matches
// the visitor's language. Each section is plain structured data so the copy
// stays easy to maintain.

export const agreementUpdated = '2026'

export const agreement = {
  hy: {
    title: 'Օգտատիրոջ համաձայնագիր',
    updatedLabel: 'Վերջին թարմացումը',
    intro: [
      'Սույն Օգտատիրոջ համաձայնագիրը (այսուհետ՝ «Համաձայնագիր») կարգավորում է www.HinYerevan.com կայքից (այսուհետ՝ «Կայք») օգտվելու պայմանները։ Կայքը նվիրված է Երևանի և Հայաստանի պատմությանը՝ պատմական լուսանկարների ու տեսանյութերի միջոցով։',
      'Գրանցվելով Կայքում, նյութեր ավելացնելով կամ Կայքից այլ կերպ օգտվելով՝ դուք հաստատում եք, որ ծանոթացել եք սույն Համաձայնագրին և ընդունում եք այն ամբողջությամբ։ Եթե համաձայն չեք պայմաններից որևէ մեկի հետ, խնդրում ենք չօգտվել Կայքից։',
    ],
    sections: [
      {
        heading: '1. Ընդհանուր դրույթներ',
        paragraphs: [
          'Կայքի նպատակն է հավաքել, պահպանել և հանրահռչակել Երևանի ու Հայաստանի պատմական ժառանգությունը։ Կայքի աշխատանքն ապահովում է Ադմինիստրացիան։',
        ],
      },
      {
        heading: '2. Հասկացություններ',
        list: [
          '«Ադմինիստրացիա»՝ Կայքը կառավարող և սպասարկող անձինք։',
          '«Օգտատեր»՝ ցանկացած անձ, ով այցելում է Կայք կամ ունի գրանցված հաշիվ։',
          '«Բովանդակություն»՝ լուսանկարներ, տեսանյութեր, նկարագրություններ, մեկնաբանություններ և Կայքում տեղադրված ցանկացած այլ նյութ։',
        ],
      },
      {
        heading: '3. Գրանցում և հաշիվ',
        paragraphs: [
          'Որոշ գործառույթներ հասանելի են միայն գրանցումից հետո։ Դուք պարտավորվում եք տրամադրել ճշգրիտ տվյալներ և գաղտնի պահել ձեր մուտքի տվյալները։',
          'Դուք պատասխանատու եք ձեր հաշվի ներքո կատարված բոլոր գործողությունների համար։ Հաշիվը երրորդ անձի կողմից օգտագործվելու դեպքում անհապաղ տեղեկացրեք Ադմինիստրացիային։',
        ],
      },
      {
        heading: '4. Օգտատիրոջ բովանդակությունը',
        paragraphs: [
          'Ձեր ավելացրած Բովանդակության իրավունքները մնում են ձեզ։ Կայքում այն հրապարակելով՝ դուք Ադմինիստրացիային տրամադրում եք ոչ բացառիկ, անհատույց իրավունք՝ պահելու, ցուցադրելու և Կայքի շրջանակում տարածելու այն՝ պատմական ժառանգության պահպանման և հանրահռչակման նպատակով։',
          'Դուք հաստատում եք, որ ունեք ավելացվող Բովանդակության անհրաժեշտ իրավունքները կամ որ այն հանրային սեփականություն է, և դրա հրապարակումը չի խախտում երրորդ անձանց իրավունքները։',
        ],
      },
      {
        heading: '5. Վարքագծի կանոններ',
        paragraphs: ['Կայքից օգտվելիս արգելվում է՝'],
        list: [
          'ավելացնել նյութեր, որոնք խախտում են օրենքը, հեղինակային իրավունքները կամ երրորդ անձանց իրավունքները;',
          'հրապարակել վիրավորական, զրպարտող կամ ակնհայտ կեղծ տեղեկություն;',
          'տեղադրել գովազդ, սպամ կամ Կայքի թեմային չառնչվող բովանդակություն;',
          'խոչընդոտել Կայքի բնականոն աշխատանքը կամ փորձել չթույլատրված մուտք գործել դրա համակարգեր։',
        ],
      },
      {
        heading: '6. Հեղինակային իրավունք',
        paragraphs: [
          'Մենք հարգում ենք մտավոր սեփականության իրավունքները։ Եթե կարծում եք, որ որևէ Բովանդակություն խախտում է ձեր իրավունքները, դիմեք Ադմինիստրացիային հետադարձ կապի ձևի միջոցով, և այդ Բովանդակությունը կուսումնասիրվի ու անհրաժեշտության դեպքում կհեռացվի։',
        ],
      },
      {
        heading: '7. Մոդերացիա',
        paragraphs: [
          'Ողջ հրապարակվող Բովանդակությունը կարող է ենթարկվել մոդերացիայի։ Ադմինիստրացիան իրեն իրավունք է վերապահում առանց նախնական ծանուցման խմբագրել, տեղափոխել կամ հեռացնել Բովանդակությունը, ինչպես նաև սահմանափակել կամ արգելափակել սույն Համաձայնագիրը խախտող հաշիվները։',
        ],
      },
      {
        heading: '8. Անձնական տվյալներ',
        paragraphs: [
          'Գրանցման ընթացքում տրամադրված անձնական տվյալներն օգտագործվում են բացառապես Կայքի աշխատանքն ապահովելու համար և չեն փոխանցվում երրորդ անձանց, բացառությամբ օրենքով նախատեսված դեպքերի։',
        ],
      },
      {
        heading: '9. Պատասխանատվության սահմանափակում',
        paragraphs: [
          'Կայքը և նրա Բովանդակությունը տրամադրվում են «ինչպես կա» սկզբունքով։ Ադմինիստրացիան չի երաշխավորում Օգտատերերի ավելացրած պատմական տեղեկության ճշգրտությունը և պատասխանատվություն չի կրում Կայքից օգտվելու հետևանքով առաջացած վնասների համար։',
        ],
      },
      {
        heading: '10. Համաձայնագրի փոփոխություններ',
        paragraphs: [
          'Ադմինիստրացիան կարող է ցանկացած պահի փոփոխել սույն Համաձայնագիրը։ Գործող տարբերակը միշտ հասանելի է այս էջում։ Փոփոխություններից հետո Կայքից շարունակ օգտվելը նշանակում է թարմացված Համաձայնագրի ընդունում։',
        ],
      },
      {
        heading: '11. Կապ',
        paragraphs: [
          'Սույն Համաձայնագրի վերաբերյալ հարցերի դեպքում դիմեք մեզ Կայքի հետադարձ կապի ձևի միջոցով։',
        ],
      },
    ],
  },

  ru: {
    title: 'Пользовательское соглашение',
    updatedLabel: 'Последнее обновление',
    intro: [
      'Настоящее Пользовательское соглашение (далее — «Соглашение») регулирует условия использования сайта www.HinYerevan.com (далее — «Сайт») — онлайн-архива, посвящённого истории Еревана и Армении через старинные фотографии и видео.',
      'Регистрируясь на Сайте, загружая материалы или иным образом пользуясь его возможностями, вы подтверждаете, что ознакомились с настоящим Соглашением и принимаете его в полном объёме. Если вы не согласны с каким-либо из условий, пожалуйста, не используйте Сайт.',
    ],
    sections: [
      {
        heading: '1. Общие положения',
        paragraphs: [
          'Цель Сайта — собирать, сохранять и популяризировать историческое наследие Еревана и Армении. Работу Сайта обеспечивает Администрация.',
        ],
      },
      {
        heading: '2. Термины и определения',
        list: [
          '«Администрация» — лица, осуществляющие управление и поддержку Сайта.',
          '«Пользователь» — любое лицо, посещающее Сайт или имеющее зарегистрированную учётную запись.',
          '«Контент» — фотографии, видео, описания, комментарии и любые другие материалы, размещённые на Сайте.',
        ],
      },
      {
        heading: '3. Регистрация и учётная запись',
        paragraphs: [
          'Часть функций доступна только после регистрации. Вы обязуетесь предоставлять достоверные данные и сохранять конфиденциальность данных для входа.',
          'Вы несёте ответственность за все действия, совершённые под вашей учётной записью. При любом несанкционированном использовании немедленно уведомите Администрацию.',
        ],
      },
      {
        heading: '4. Пользовательский контент',
        paragraphs: [
          'Права на загружаемый вами Контент остаются за вами. Публикуя его на Сайте, вы предоставляете Администрации неисключительное безвозмездное право хранить, отображать и распространять его в рамках Сайта с целью сохранения и популяризации исторического наследия.',
          'Вы подтверждаете, что обладаете необходимыми правами на загружаемый Контент либо что он является общественным достоянием, и его публикация не нарушает прав третьих лиц.',
        ],
      },
      {
        heading: '5. Правила поведения',
        paragraphs: ['При использовании Сайта запрещается:'],
        list: [
          'загружать материалы, нарушающие закон, авторские права или права третьих лиц;',
          'публиковать оскорбительную, клеветническую или заведомо ложную информацию;',
          'размещать рекламу, спам или контент, не относящийся к тематике Сайта;',
          'мешать нормальной работе Сайта или пытаться получить несанкционированный доступ к его системам.',
        ],
      },
      {
        heading: '6. Авторские права',
        paragraphs: [
          'Мы уважаем права на интеллектуальную собственность. Если вы считаете, что какой-либо Контент нарушает ваши права, обратитесь к Администрации через форму обратной связи — такой Контент будет рассмотрен и при необходимости удалён.',
        ],
      },
      {
        heading: '7. Модерация',
        paragraphs: [
          'Весь публикуемый Контент может проходить модерацию. Администрация оставляет за собой право без предварительного уведомления редактировать, перемещать или удалять Контент, а также ограничивать или блокировать учётные записи, нарушающие настоящее Соглашение.',
        ],
      },
      {
        heading: '8. Персональные данные',
        paragraphs: [
          'Персональные данные, предоставленные при регистрации, используются исключительно для работы Сайта и не передаются третьим лицам, за исключением случаев, предусмотренных законом.',
        ],
      },
      {
        heading: '9. Ограничение ответственности',
        paragraphs: [
          'Сайт и его Контент предоставляются «как есть». Администрация не гарантирует точность исторической информации, добавленной Пользователями, и не несёт ответственности за ущерб, возникший вследствие использования Сайта.',
        ],
      },
      {
        heading: '10. Изменения Соглашения',
        paragraphs: [
          'Администрация вправе в любое время изменять настоящее Соглашение. Действующая редакция всегда доступна на этой странице. Продолжение использования Сайта после внесения изменений означает принятие обновлённого Соглашения.',
        ],
      },
      {
        heading: '11. Контакты',
        paragraphs: [
          'По всем вопросам, связанным с настоящим Соглашением, свяжитесь с нами через форму обратной связи на Сайте.',
        ],
      },
    ],
  },

  en: {
    title: 'User Agreement',
    updatedLabel: 'Last updated',
    intro: [
      'This User Agreement (the “Agreement”) governs your use of the website www.HinYerevan.com (the “Site”), an online archive dedicated to the history of Yerevan and Armenia through historical photographs and videos.',
      'By registering on the Site, uploading materials, or otherwise using its features, you confirm that you have read this Agreement and accept it in full. If you do not agree with any of its terms, please do not use the Site.',
    ],
    sections: [
      {
        heading: '1. General Provisions',
        paragraphs: [
          'The purpose of the Site is to collect, preserve, and popularize the historical heritage of Yerevan and Armenia. The Site is operated and maintained by the Administration.',
        ],
      },
      {
        heading: '2. Definitions',
        list: [
          '“Administration” — the persons who manage and maintain the Site.',
          '“User” — any person who visits the Site or holds a registered account.',
          '“Content” — photographs, videos, descriptions, comments, and any other materials placed on the Site.',
        ],
      },
      {
        heading: '3. Registration and Account',
        paragraphs: [
          'Some features are available only after registration. You agree to provide accurate information and to keep your login credentials confidential.',
          'You are responsible for all activity performed under your account. Notify the Administration immediately of any unauthorized use.',
        ],
      },
      {
        heading: '4. User Content',
        paragraphs: [
          'You retain the rights to the Content you upload. By publishing it on the Site, you grant the Administration a non-exclusive, royalty-free right to store, display, and distribute it within the Site for the purpose of preserving and popularizing historical heritage.',
          'You confirm that you hold the necessary rights to the Content you upload, or that it is in the public domain, and that its publication does not infringe the rights of third parties.',
        ],
      },
      {
        heading: '5. Rules of Conduct',
        paragraphs: ['When using the Site, it is prohibited to:'],
        list: [
          'upload materials that violate the law, copyright, or the rights of third parties;',
          'publish offensive, defamatory, or knowingly false information;',
          'post advertising, spam, or content unrelated to the subject of the Site;',
          'interfere with the normal operation of the Site or attempt unauthorized access to its systems.',
        ],
      },
      {
        heading: '6. Copyright',
        paragraphs: [
          'We respect intellectual property rights. If you believe that any Content infringes your rights, please contact the Administration through the feedback form, and such Content will be reviewed and, if necessary, removed.',
        ],
      },
      {
        heading: '7. Moderation',
        paragraphs: [
          'All published Content may be moderated. The Administration reserves the right to edit, move, or remove Content, and to restrict or block accounts that violate this Agreement, without prior notice.',
        ],
      },
      {
        heading: '8. Personal Data',
        paragraphs: [
          'Personal data provided during registration is used solely to operate the Site and is not shared with third parties, except as required by law.',
        ],
      },
      {
        heading: '9. Limitation of Liability',
        paragraphs: [
          'The Site and its Content are provided “as is”. The Administration does not guarantee the accuracy of historical information added by Users and is not liable for any damage arising from the use of the Site.',
        ],
      },
      {
        heading: '10. Changes to the Agreement',
        paragraphs: [
          'The Administration may amend this Agreement at any time. The current version is always available on this page. Continued use of the Site after changes take effect constitutes acceptance of the updated Agreement.',
        ],
      },
      {
        heading: '11. Contact',
        paragraphs: [
          'For any questions regarding this Agreement, please contact us through the feedback form on the Site.',
        ],
      },
    ],
  },
}

export function agreementFor(lang) {
  return agreement[lang] || agreement.hy
}
