## UPD 04.04.26

Привіт Спільното!

Вірні принципу відкритості, який завжди був основою нашої роботи, ми підготували невеликий інструмент-аудитор і надаємо до нього публічний доступ. Інструмент дозволяє в режимі реального часу бачити поточні цілі атак, стежити за їх статусом, тобто кожен учасник може самостійно контролювати і верифікувати те, що відбувається. Жодних закритих кімнат, жодних таємниць.

Слід підкреслити, що розміщення публікація даного інструменту є вимушеним заходом, який певною мірою надасть противнику змогу відстежувати наші удари що не є добре але слова адміністрації каналу IT Армія не залишили вибору окрім того щоб спростувати їх дією. Втім, варто додати, що ця можливість існувала завжди, і ми приймаємо це рішення усвідомлено, з чітким розумінням пов'язаних ризиків.

Технічно аудитор перехоплює вихідний трафік від `mhddos_proxy`, `distress` або будь-якої іншої програми та показує таблицю з усіма IP-адресами, на які надсилаються запити, кількість пакетів і обсяг трафіку. Кожен IP - це посилання на `ipinfo.io`, де можна одразу перевірити кому він належить. Запустити можна однією командою в Docker, а якщо є сумніви щодо того що відбувається всередині - достатньо скопіювати `Dockerfile` у будь-який LLM і попросити пояснити.

**ІНСТРУМЕНТ ДЛЯ НЕЗАЛЕЖНОГО АУДИТУ ЦІЛЕЙ ЗНАХОДИТЬСЯ ТУТ:** https://github.com/porthole-ascend-cinnamon/targets_audit

Кожен охочий, самостійно або за допомогою будь-якого LLM, може переконатися що для проведення цього аудиту достатньо мінімальної технічної компетентності. Жодної потреби лізти у чутливий код не було і немає, особливо тим, хто очевидно в ньому нічого не тямить, адже саме нездатність зробити цей аудит самостійно і спонукала до вимоги повного доступу. Попри це, адміністратор групи IT Армії продовжував наполягати на повному доступі до технічної інфраструктури, ігноруючи всі наведені аргументи.

Так, ми формально закрили відкритий доступ до цілей свого часу, але завжди чітко розуміли що це зупинить лише випадкових людей. До таких, очевидно, і належить згаданий адміністратор. Технічно приховати подібні речі неможливо, що ми і демонструємо зараз. І, на жаль, цим давно користувалися вороги.

### Хто насправді стоїть за операціями?

Команда розробки та розвідки, яка відповідала за всі операції останні чотири роки, не знає жодної організаційної структури під назвою "IT Армія" окрім 1–2 адмінів чату, які завжди позиціонували себе як таких самих волонтерів. Тому заяви на кшталт "ми не можемо переконатися" розбиваються об просту реальність: жодного "ми" не існує. IT Армія завжди була розподіленою волонтерською спільнотою. Адміни каналу не мають жодного відношення ні до організації атак, ні до підтримки інструментів.

Адмін каналу зажадав повного доступу як до розвідки, так і до розробників, без жодного зрозумілого обґрунтування. Перед десятком розробників, які в ITAU з самого початку, він не зміг пояснити своїх намірів. Натомість пригрозив оголосити нам недовіру і самостійно зібрати нову команду. Ми вважаємо, що спільнота має знати про це.

Закиди щодо непрозорості це не більше ніж вигадка. Інструмент перед вами, цілі перед вами. Аудит доступний кожному прямо зараз.

Тож ми заохочуємо всіх до використання аудитору та підтримки наших ідеалів, чекаємо на ваші відгуки та коментарі.

Відкритість - це не слабкість. Це наша сила.

## UPD 04.04.26 (EN)

Hello Community!

Staying true to the principle of openness, which has always been the foundation of our work, we have prepared a small auditing tool and are making it publicly available. The tool makes it possible to see current attack targets in real time and track their status, so every participant can independently monitor and verify what is happening. No closed rooms, no secrets.

It should be emphasized that publishing this tool is a forced measure. To some extent, it will allow the enemy to track our strikes, which is not good, but the statements made by the IT Army channel administration left us no choice except to refute them through action. At the same time, it is worth adding that this possibility has always existed, and we are making this decision consciously, with a clear understanding of the related risks.

Technically, the auditor intercepts outgoing traffic from `mhddos_proxy`, `distress`, or any other program and shows a table with all IP addresses to which requests are being sent, the number of packets, and the amount of traffic. Each IP is a link to `ipinfo.io`, where you can immediately check who it belongs to. It can be launched with a single Docker command, and if there are doubts about what is happening inside, it is enough to copy the `Dockerfile` into any LLM and ask for an explanation.

**THE TOOL FOR INDEPENDENT TARGET AUDIT IS AVAILABLE HERE:** https://github.com/porthole-ascend-cinnamon/targets_audit

Anyone, independently or with the help of any LLM, can verify that only minimal technical competence is required to perform this audit. There was and is no need to dig into sensitive code, especially for those who obviously do not understand it at all, since the inability to perform this audit independently is exactly what prompted the demand for full access. Despite this, the administrator of the IT Army group continued to insist on full access to the technical infrastructure, ignoring all the arguments presented.

Yes, at one point we formally closed public access to targets, but we always clearly understood that this would stop only random people. The mentioned administrator obviously belongs to that category. Technically, it is impossible to hide such things, which is exactly what we are demonstrating now. And unfortunately, the enemy has been taking advantage of this for a long time.

### Who is really behind the operations?

The development and intelligence team that has been responsible for all operations over the last four years does not know of any organizational structure called "IT Army" other than 1-2 chat admins who have always presented themselves as the same kind of volunteers. Therefore, statements like "we cannot verify" collapse against a simple reality: there is no such "we." IT Army has always been a distributed volunteer community. The channel admins have nothing to do with either organizing the attacks or maintaining the tools.

The channel admin demanded full access to both intelligence and developers without any clear justification. In front of a dozen developers who have been in ITAU from the very beginning, he could not explain his intentions. Instead, he threatened to declare distrust in us and assemble a new team on his own. We believe the community should know about this.

Claims of non-transparency are nothing more than fiction. The tool is before you, the targets are before you. The audit is available to everyone right now.

We therefore encourage everyone to use the auditor and support our ideals, and we look forward to your feedback and comments.

Openness is not weakness. It is our strength.

# УВАГА! ⚠️

Утіліти IT ARMY а також команда розвідки в повному складі залишаються вірними патріотами України, які працюють проти ворога кожен день.  
Всі розвідники і розробники утіліт, які працювали з вами всі 4+ років, залишаються єдиними в своїй позиції, без винятку.

Інформація про компрометацію нашої команди є ініціативою адміністратора ТГ-каналу, який, спираючись на непідтверджені зв’язки з МінЦифри, намагався отримати доступ до чутливого коду та конфігурації під виглядом необхідного аудиту, хоча багато років він цього аудиту не потребував.

Оскільки ця особа не підтвердила ані організацію, яку він представляє, ані мету своїх потреб, ми були вимушені відмовити задля безпеки.  
Ми інформуємо вас через утіліти, бо не маємо доступу до офіційного каналу.

Якщо ви сумніваєтеся в поточних цілях, ви завжди можете подивитися, куди спрямовано трафік.  
Ми просимо вас не зупиняти сервери, оскільки робота продовжується і від адміністратора ТГ каналу ви не отримаєте правдивої інформації.

Скоріше за все, ми будемо створювати новий канал. Очікуйте нову інформацію через утіліти.

Дякуємо за довіру. Слава Україні!

# ATTENTION! ⚠️

IT ARMY utilities, as well as the full intelligence team, remain loyal patriots of Ukraine who work against the enemy every day.  
All intelligence members and utility developers who have worked with you for more than 4 years remain united in their position, without exception.

Information about the alleged compromise of our team is an initiative of the Telegram channel administrator who, relying on unconfirmed ties to the Ministry of Digital Transformation, attempted to gain access to sensitive code and configuration under the guise of a necessary audit, even though for many years no such audit had been requested.

Since this person did not confirm either the organization they represent or the purpose of their requests, we were forced to refuse for security reasons.  
We are informing you through the utilities because we do not have access to the official channel.

If you have doubts about the current goals, you can always inspect where the traffic is directed.  
We ask you not to stop the servers, because the work continues and you will not receive truthful information from the Telegram channel administrator.

Most likely, we will create a new channel. Expect new information through the utilities.

Thank you for your trust. Glory to Ukraine!
