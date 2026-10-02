"""
Hindu festival and vrat dates, computed from the Sun and the Moon.

    pip install pyswisseph
    python3 tool/panchang/festivals.py 2026 2028 > database/data/festivals.json

The same method printed panchangs use, with the Swiss Ephemeris (its built-in
Moshier ephemeris, so no data files): tithis from the Moon-Sun elongation,
amanta lunar months named from the sidereal (Lahiri) sign of the Sun at the
new moon that opens them, an Adhik Maas when two new moons fall in one sign,
and each festival's own rule for which day it is kept on: the tithi at
sunrise, at midday (madhyahna), in the afternoon (aparahna), at dusk
(pradosh), at midnight (nishita) or at moonrise. Solar festivals follow the
Sun's entry into a sign; Onam and Thaipusam a nakshatra in a solar month.

Reference place: New Delhi, the convention of all-India panchangs. Regional
almanacs can differ by a day; the admin can correct any date.
"""

import json
import math
import sys
from datetime import date, datetime, timedelta, timezone

import swisseph as swe

LAT, LON, ALT = 28.6139, 77.2090, 216
IST = timezone(timedelta(hours=5, minutes=30))
FLAGS = swe.FLG_MOSEPH | swe.FLG_SPEED
swe.set_sid_mode(swe.SIDM_LAHIRI)

MONTHS = ['Chaitra', 'Vaishakha', 'Jyeshtha', 'Ashadha', 'Shravana', 'Bhadrapada',
          'Ashwin', 'Kartika', 'Margashirsha', 'Pausha', 'Magha', 'Phalguna']
TITHIS = ['Pratipada', 'Dwitiya', 'Tritiya', 'Chaturthi', 'Panchami', 'Shashthi', 'Saptami',
          'Ashtami', 'Navami', 'Dashami', 'Ekadashi', 'Dwadashi', 'Trayodashi', 'Chaturdashi']


# --- Astronomy -----------------------------------------------------------------

def jd_of(dt):
    u = dt.astimezone(timezone.utc)
    return swe.julday(u.year, u.month, u.day, u.hour + u.minute / 60 + u.second / 3600)


def dt_of(jd):
    y, m, d, h = swe.revjul(jd)
    return (datetime(y, m, d, tzinfo=timezone.utc) + timedelta(hours=h)).astimezone(IST)


def lon(body, jd, sidereal=False):
    flags = FLAGS | (swe.FLG_SIDEREAL if sidereal else 0)
    return swe.calc_ut(jd, body, flags)[0][0]


def elongation(jd):
    return (lon(swe.MOON, jd) - lon(swe.SUN, jd)) % 360


def tithi_at(jd):
    """1..30: Shukla 1-15, Krishna 16-30 (30 = Amavasya)."""
    return int(elongation(jd) // 12) + 1


def nakshatra_at(jd):
    """0..26, Ashwini = 0, from the Moon's sidereal longitude."""
    return int(lon(swe.MOON, jd, True) // (360 / 27))


def sun_sign(jd):
    """0..11, Mesha = 0, sidereal."""
    return int(lon(swe.SUN, jd, True) // 30)


def find(fn, target, a, b):
    """The moment in [a, b] where an increasing angle fn crosses target (mod 360)."""
    for _ in range(60):
        m = (a + b) / 2
        if ((fn(m) - target + 180) % 360) - 180 < 0:
            a = m
        else:
            b = m
    return (a + b) / 2


def rise_set(day, body=swe.SUN, rise=True):
    """Rise or set of a body on a civil day at New Delhi, as a Julian day."""
    start = jd_of(datetime(day.year, day.month, day.day, tzinfo=IST))
    flag = swe.CALC_RISE if rise else swe.CALC_SET
    res = swe.rise_trans(start, body, flag, (LON, LAT, ALT), 1013.25, 15, swe.FLG_MOSEPH)
    return res[1][0]


_day_cache = {}


def day_info(day):
    if day not in _day_cache:
        sr = rise_set(day)
        ss = rise_set(day, rise=False)
        nsr = rise_set(day + timedelta(days=1))
        _day_cache[day] = (sr, ss, nsr)
    return _day_cache[day]


def kala_time(day, kala):
    sr, ss, nsr = day_info(day)
    span = ss - sr
    return {
        'sunrise': sr,
        'madhyahna': sr + span * 0.5,       # middle of the third fifth of the day
        'aparahna': sr + span * 0.7,        # middle of the fourth fifth
        'pradosh': ss + 1.2 / 24,           # 72 minutes after sunset
        'nishita': ss + (nsr - ss) / 2,     # midnight between sunset and sunrise
        'moonrise': None,
    }[kala]


def moonrise(day):
    try:
        return rise_set(day, swe.MOON)
    except Exception:
        return None


# --- Lunar months --------------------------------------------------------------

def new_moons(start, end):
    out = []
    jd = jd_of(datetime(start.year, start.month, start.day, tzinfo=IST)) - 35
    stop = jd_of(datetime(end.year, end.month, end.day, tzinfo=IST)) + 35
    while jd < stop:
        # step a day at a time until the elongation wraps past 360
        a = jd
        e0 = elongation(a)
        while True:
            b = a + 1
            e1 = elongation(b)
            if e1 < e0:
                out.append(find(elongation, 0, a, b))
                jd = b + 25
                break
            a, e0 = b, e1
    return out


def lunar_months(start, end):
    """[(name_index, is_adhik, start_jd, end_jd)] for amanta months."""
    nms = new_moons(start, end)
    months = []
    for i in range(len(nms) - 1):
        r = sun_sign(nms[i])
        r_next = sun_sign(nms[i + 1])
        name = (r + 1) % 12
        months.append((name, r == r_next, nms[i], nms[i + 1]))
    return months


def tithi_span(m_start, m_end, t):
    """When tithi t (1..30) runs within the lunar month."""
    lo = 12 * (t - 1)
    hi = 12 * t
    a = m_start + 0.001 if t == 1 else find(elongation, lo, m_start, m_end)
    b = m_end if t == 30 else find(elongation, hi, m_start, m_end)
    return a, b


def civil_day(jd):
    return dt_of(jd).date()


def pick_day(span, kala, prefer_last=False):
    """The civil day a tithi is kept on, by the kala rule."""
    a, b = span
    d0 = civil_day(a) - timedelta(days=1)
    days = [d0 + timedelta(days=i) for i in range(4)]
    hits = []
    for d in days:
        if kala == 'moonrise':
            t = moonrise(d)
        elif kala == 'daytime':
            sr, ss, _ = day_info(d)
            overlap = max(0, min(b, ss) - max(a, sr))
            if overlap > 0:
                hits.append((overlap, d))
            continue
        else:
            t = kala_time(d, kala)
        if t is not None and a <= t < b:
            hits.append(d)
    if kala == 'daytime':
        return max(hits)[1] if hits else civil_day(a)
    if hits:
        return hits[-1] if prefer_last else hits[0]
    # Not present at that time on any day: the day it is current at sunrise,
    # else the day it begins.
    for d in days:
        if a <= kala_time(d, 'sunrise') < b:
            return d
    return civil_day(a)


# --- What is kept when ---------------------------------------------------------

S = lambda n: n          # Shukla tithi n
K = lambda n: 15 + n     # Krishna tithi n (amanta: after the month's Shukla half)

# (key, name, month, tithi, kala, kind, major, deity, description)
LUNAR = [
    ('ugadi', 'Ugadi / Gudi Padwa', 0, S(1), 'sunrise', 'festival', True, None, 'Hindu New Year (Chaitra Shukla Pratipada): Ugadi in Andhra Pradesh, Telangana and Karnataka, Gudi Padwa in Maharashtra.'),
    ('chaitra-navratri', 'Chaitra Navratri begins', 0, S(1), 'sunrise', 'festival', True, 'durga', 'Nine nights of the Goddess in spring, ending on Rama Navami.'),
    ('rama-navami', 'Sri Rama Navami', 0, S(9), 'madhyahna', 'festival', True, 'rama', 'Birth of Lord Rama, Chaitra Shukla Navami. Sita Rama Kalyanam at Bhadrachalam.'),
    ('hanuman-jayanti', 'Hanuman Jayanti', 0, S(15), 'sunrise', 'festival', True, 'hanuman', 'Birth of Lord Hanuman, Chaitra Purnima (North India).'),
    ('akshaya-tritiya', 'Akshaya Tritiya', 1, S(3), 'sunrise', 'festival', True, 'vishnu', 'Vaishakha Shukla Tritiya: an auspicious day for beginnings, charity and gold.'),
    ('narasimha-jayanti', 'Narasimha Jayanti', 1, S(14), 'pradosh', 'festival', False, 'narasimha', 'Appearance of Lord Narasimha at dusk, Vaishakha Shukla Chaturdashi.'),
    ('buddha-purnima', 'Buddha Purnima', 1, S(15), 'sunrise', 'festival', False, None, 'Vaishakha Purnima.'),
    ('vat-savitri', 'Vat Savitri Vrat', 1, K(15), 'sunrise', 'vrat', False, None, 'Married women pray at the banyan tree for their husbands (Jyeshtha Amavasya, North India).'),
    ('ganga-dussehra', 'Ganga Dussehra', 2, S(10), 'sunrise', 'festival', False, None, 'Descent of the Ganga, Jyeshtha Shukla Dashami.'),
    ('rath-yatra', 'Jagannath Rath Yatra', 3, S(2), 'sunrise', 'festival', True, 'jagannath', 'The chariot festival of Puri, Ashadha Shukla Dwitiya.'),
    ('guru-purnima', 'Guru Purnima', 3, S(15), 'sunrise', 'festival', True, None, 'Ashadha Purnima, honouring the guru and Veda Vyasa.'),
    ('hariyali-teej', 'Hariyali Teej', 4, S(3), 'sunrise', 'festival', False, 'parvati', 'Shravana Shukla Tritiya.'),
    ('nag-panchami', 'Nag Panchami', 4, S(5), 'sunrise', 'festival', True, None, 'Worship of the serpent deities, Shravana Shukla Panchami.'),
    ('raksha-bandhan', 'Raksha Bandhan', 4, S(15), 'sunrise', 'festival', True, None, 'Shravana Purnima. Also Upakarma (Avani Avittam) and Narali Purnima.'),
    ('krishna-janmashtami', 'Krishna Janmashtami', 4, K(8), 'sunrise', 'festival', True, 'krishna', 'Birth of Lord Krishna, Bhadrapada Krishna Ashtami (purnimanta); midnight puja.'),
    ('ganesh-chaturthi', 'Ganesh Chaturthi', 5, S(4), 'madhyahna', 'festival', True, 'ganesha', 'Vinayaka Chaturthi, Bhadrapada Shukla Chaturthi.'),
    ('rishi-panchami', 'Rishi Panchami', 5, S(5), 'madhyahna', 'vrat', False, None, 'Bhadrapada Shukla Panchami.'),
    ('anant-chaturdashi', 'Anant Chaturdashi', 5, S(14), 'sunrise', 'festival', True, 'ganesha', 'Ganesh Visarjan, Bhadrapada Shukla Chaturdashi.'),
    ('mahalaya-amavasya', 'Mahalaya Amavasya', 5, K(15), 'sunrise', 'festival', True, None, 'Sarva Pitru Amavasya, the last day of Pitru Paksha.'),
    ('sharad-navratri', 'Sharad Navratri begins', 6, S(1), 'sunrise', 'festival', True, 'durga', 'Nine nights of the Goddess. Ghatasthapana. Bathukamma begins in Telangana.'),
    ('durga-puja', 'Durga Puja (Maha Shashthi)', 6, S(6), 'sunrise', 'festival', False, 'durga', 'Bengal\'s Durga Puja begins.'),
    ('durga-ashtami', 'Durga Ashtami', 6, S(8), 'sunrise', 'festival', True, 'durga', 'Maha Ashtami.'),
    ('maha-navami', 'Maha Navami', 6, S(9), 'sunrise', 'festival', True, 'durga', 'Ayudha Puja. Saddula Bathukamma in Telangana.'),
    ('dussehra', 'Dussehra / Vijayadashami', 6, S(10), 'aparahna', 'festival', True, 'rama', 'Victory of good over evil, Ashwin Shukla Dashami.'),
    ('sharad-purnima', 'Sharad Purnima', 6, S(15), 'pradosh', 'festival', False, 'lakshmi', 'Kojagari Lakshmi Puja on the full moon night.'),
    ('karva-chauth', 'Karva Chauth', 6, K(4), 'moonrise', 'vrat', True, None, 'Fast by married women until moonrise (North India).'),
    ('ahoi-ashtami', 'Ahoi Ashtami', 6, K(8), 'pradosh', 'vrat', False, None, 'Mothers fast for their children.'),
    ('dhanteras', 'Dhanteras', 6, K(13), 'pradosh', 'festival', True, 'lakshmi', 'Dhanvantari Jayanti, the first day of Diwali.'),
    ('naraka-chaturdashi', 'Naraka Chaturdashi', 6, K(14), 'sunrise', 'festival', True, 'krishna', 'Choti Diwali; the early bath, Deepavali in South India.'),
    ('diwali', 'Diwali (Lakshmi Puja)', 6, K(15), 'diwali', 'festival', True, 'lakshmi', 'Festival of lights: Lakshmi Puja on Kartika Amavasya.'),
    ('govardhan-puja', 'Govardhan Puja', 7, S(1), 'sunrise', 'festival', True, 'krishna', 'Annakut, the day after Diwali.'),
    ('bhai-dooj', 'Bhai Dooj', 7, S(2), 'sunrise', 'festival', True, None, 'Yama Dwitiya, sisters and brothers.'),
    ('chhath', 'Chhath Puja (Sandhya Arghya)', 7, S(6), 'sunrise', 'festival', True, 'surya', 'Evening offering to the setting Sun, Kartika Shukla Shashthi. Skanda Sashti in Tamil Nadu.'),
    ('tulsi-vivah', 'Tulsi Vivah', 7, S(12), 'sunrise', 'festival', False, 'vishnu', 'Marriage of Tulsi and Shaligram.'),
    ('kartik-purnima', 'Kartika Purnima / Dev Deepavali', 7, S(15), 'sunrise', 'festival', True, 'shiva', 'Dev Deepavali at Varanasi; Kartika Deepam.'),
    ('vivah-panchami', 'Vivah Panchami', 8, S(5), 'sunrise', 'festival', False, 'rama', 'Marriage of Rama and Sita.'),
    ('gita-jayanti', 'Gita Jayanti', 8, S(11), 'sunrise', 'festival', False, 'krishna', 'The day the Bhagavad Gita was spoken.'),
    ('dattatreya-jayanti', 'Dattatreya Jayanti', 8, S(15), 'pradosh', 'festival', False, None, 'Margashirsha Purnima.'),
    ('vaikuntha-ekadashi', 'Vaikuntha Ekadashi', 9, S(11), 'daytime', 'festival', True, 'venkateswara', 'Vaikuntha Dwaram opens at Vishnu temples, Tirumala and Srirangam.'),
    ('vasant-panchami', 'Vasant Panchami', 10, S(5), 'sunrise', 'festival', True, 'saraswati', 'Saraswati Puja, Magha Shukla Panchami.'),
    ('ratha-saptami', 'Ratha Saptami', 10, S(7), 'sunrise', 'festival', False, 'surya', 'Surya Jayanti, Magha Shukla Saptami.'),
    ('maha-shivaratri', 'Maha Shivaratri', 10, K(14), 'nishita', 'festival', True, 'shiva', 'The great night of Shiva, Phalguna Krishna Chaturdashi (purnimanta).'),
    ('holika-dahan', 'Holika Dahan', 11, S(15), 'pradosh', 'festival', True, None, 'The bonfire on Phalguna Purnima; Holi is the next day.'),
]

EKADASHI = {  # amanta month: (Shukla name, Krishna name)
    0: ('Kamada', 'Varuthini'), 1: ('Mohini', 'Apara'), 2: ('Nirjala', 'Yogini'),
    3: ('Devshayani', 'Kamika'), 4: ('Shravana Putrada', 'Aja'), 5: ('Parsva', 'Indira'),
    6: ('Papankusha', 'Rama'), 7: ('Devutthana', 'Utpanna'), 8: ('Mokshada', 'Saphala'),
    9: ('Pausha Putrada', 'Shattila'), 10: ('Jaya', 'Vijaya'), 11: ('Amalaki', 'Papamochani'),
}


def slug(s):
    return ''.join(c if c.isalnum() else '-' for c in s.lower()).strip('-').replace('--', '-')


def compute(y0, y1):
    start, end = date(y0, 1, 1), date(y1, 12, 31)
    rows = []

    def add(key, name, day, kind, major, deity, desc, label, ends=None):
        if start <= day <= end:
            rows.append({
                'key': key, 'name': name, 'date': day.isoformat(),
                'ends_on': ends.isoformat() if ends else None,
                'kind': kind, 'is_major': major, 'deity': deity,
                'description': desc, 'tithi': label,
            })

    for (m, adhik, ms, me) in lunar_months(start, end):
        mname = ('Adhik ' if adhik else '') + MONTHS[m]

        def label(t):
            return f"{mname} {'Shukla' if t <= 15 else 'Krishna'} {('Purnima' if t == 15 else 'Amavasya' if t == 30 else TITHIS[(t - 1) % 15])}"

        if not adhik:
            for key, name, mon, t, kala, kind, major, deity, desc in LUNAR:
                if mon != m:
                    continue
                span = tithi_span(ms, me, t)
                if kala == 'diwali':
                    # Lakshmi Puja: Amavasya in the evening; if it still holds a
                    # ghati after sunset on the second day, the second day.
                    a, b = span
                    first = civil_day(a)
                    cands = [first - timedelta(days=1) + timedelta(days=i) for i in range(3)]
                    ok = [d for d in cands if a <= day_info(d)[1] + 24 / 1440 < b]
                    day = ok[-1] if ok else pick_day(span, 'pradosh')
                else:
                    day = pick_day(span, kala)
                if key == 'holika-dahan':
                    # Bhadra (Vishti karana, the first half of Purnima): the
                    # bonfire waits until it ends; if it lasts past midnight,
                    # it is lit the next evening.
                    bhadra_end = find(elongation, 174, span[0], span[1])
                    if bhadra_end > kala_time(day, 'nishita'):
                        day = day + timedelta(days=1)
                add(key, name, day, kind, major, deity, desc, label(t))
                if key == 'holika-dahan':
                    add('holi', 'Holi', day + timedelta(days=1), 'festival', True, 'krishna', 'Festival of colours, the day after Holika Dahan.', label(t))
                if key == 'sharad-navratri':
                    add('bathukamma', 'Bathukamma begins (Engili Pula)', day - timedelta(days=1), 'festival', False, 'parvati', 'Telangana\'s floral festival of the Goddess, from Mahalaya Amavasya.', label(t))

        # Every month: Ekadashis, Purnima, Amavasya, Sankashti, Pradosh, Masik Shivaratri.
        for t, paksha in ((11, 0), (26, 1)):
            names = ('Padmini', 'Parama') if adhik else EKADASHI[m]
            n = names[paksha]
            add('ekadashi', f'{n} Ekadashi', pick_day(tithi_span(ms, me, t), 'daytime'), 'vrat', n in ('Nirjala', 'Devshayani', 'Devutthana', 'Mokshada'), 'vishnu', 'Ekadashi fast, dedicated to Lord Vishnu.', label(t))
        add('purnima', f'{mname} Purnima', pick_day(tithi_span(ms, me, 15), 'sunrise'), 'vrat', False, None, 'Full moon: Satyanarayana puja, temple utsavams.', label(15))
        add('amavasya', f'{mname} Amavasya', pick_day(tithi_span(ms, me, 30), 'sunrise'), 'vrat', False, None, 'New moon: tarpanam for the ancestors.', label(30))
        add('sankashti', 'Sankashti Chaturthi', pick_day(tithi_span(ms, me, K(4)), 'moonrise'), 'vrat', False, 'ganesha', 'Fast for Lord Ganesha until moonrise.', label(K(4)))
        for t in (S(13), K(13)):
            add('pradosh', 'Pradosh Vrat', pick_day(tithi_span(ms, me, t), 'pradosh'), 'vrat', False, 'shiva', 'Evening worship of Lord Shiva on Trayodashi.', label(t))
        add('masik-shivaratri', 'Masik Shivaratri', pick_day(tithi_span(ms, me, K(14)), 'nishita'), 'vrat', False, 'shiva', 'Monthly night of Shiva.', label(K(14)))

    # Solar: the Sun entering a sidereal sign (sankranti), kept that day, or
    # the next if it enters after sunset.
    def sankranti(year, sign):
        jd = jd_of(datetime(year, 1, 1, tzinfo=IST))
        target = sign * 30
        # walk to the crossing
        while True:
            a, b = jd, jd + 1
            la, lb = lon(swe.SUN, a, True), lon(swe.SUN, b, True)
            if ((la - target) % 360) > 300 and ((lb - target) % 360) < 60:
                t = find(lambda j: lon(swe.SUN, j, True), target, a, b)
                d = civil_day(t)
                return d + timedelta(days=1) if t > day_info(d)[1] else d
            jd = b

    for y in range(y0, y1 + 1):
        mk = sankranti(y, 9)
        add('makar-sankranti', 'Makar Sankranti', mk, 'festival', True, 'surya', 'The Sun enters Makara. Pongal in Tamil Nadu, Sankranti in Andhra Pradesh and Telangana, Uttarayan in Gujarat.', 'Makara Sankranti')
        add('bhogi', 'Bhogi', mk - timedelta(days=1), 'festival', True, None, 'The first day of Sankranti and Pongal; Lohri in Punjab.', 'Day before Makara Sankranti')
        add('pongal', 'Pongal', mk, 'festival', True, 'surya', 'Thai Pongal, the harvest festival of Tamil Nadu.', 'Thai 1')
        add('kanuma', 'Kanuma / Mattu Pongal', mk + timedelta(days=1), 'festival', False, None, 'Honouring cattle, the day after Sankranti.', 'Day after Makara Sankranti')
        ms = sankranti(y, 0)
        add('mesha-sankranti', 'Baisakhi / Vishu / Puthandu', ms, 'festival', True, None, 'Solar new year: Baisakhi in Punjab, Vishu in Kerala, Tamil Puthandu, Pohela Boishakh, Bohag Bihu.', 'Mesha Sankranti')

        # Nakshatra in a solar month: Onam (Thiruvonam in Chingam), Thaipusam
        # (Pusam in Thai), Karthigai Deepam (Krittika in Karthigai).
        def star_days(sign, star):
            d = date(y, 1, 1)
            found = []
            while d.year == y:
                sr = day_info(d)[0]
                if sun_sign(sr) == sign and nakshatra_at(sr) == star:
                    found.append(d)
                d += timedelta(days=1)
            return found

        def day_star(d):
            # The nakshatra over most of the daylight hours.
            sr, ss, _ = day_info(d)
            counts = {}
            for i in range(12):
                n = nakshatra_at(sr + (ss - sr) * (i + 0.5) / 12)
                counts[n] = counts.get(n, 0) + 1
            return max(counts, key=counts.get)

        def full_moon_star(sign, star):
            # Thaipusam and Karthigai Deepam: the star in that solar month,
            # with the full moon; if it comes twice, the one in the bright
            # half rising to the full moon.
            d, days = date(y, 1, 1), []
            while d.year == y:
                if sun_sign(day_info(d)[0] + 0.25) == sign and day_star(d) == star:
                    days.append(d)
                d += timedelta(days=1)
            waxing = [x for x in days if elongation(day_info(x)[0]) <= 180]
            pool = waxing or days
            return min(pool, key=lambda x: abs(elongation(day_info(x)[0]) - 180), default=None)

        # Onam: the Thiruvonam whose ten days, from Atham, all fall in Chingam.
        onam = [d for d in star_days(4, 21) if sun_sign(day_info(d - timedelta(days=9))[1]) == 4]
        if onam:
            add('onam', 'Onam (Thiruvonam)', onam[0], 'festival', True, 'vishnu', 'Kerala\'s harvest festival, Thiruvonam nakshatra in Chingam.', 'Chingam Thiruvonam')
        tp = full_moon_star(9, 7)
        if tp:
            add('thaipusam', 'Thaipusam', tp, 'festival', True, 'murugan', 'Festival of Lord Murugan, Pusam nakshatra with the full moon of Thai.', 'Thai Pusam')
        kd = full_moon_star(7, 2)
        if kd:
            add('karthigai-deepam', 'Karthigai Deepam', kd, 'festival', True, 'shiva', 'The festival of lamps at Tiruvannamalai, Krittika nakshatra with the full moon of Karthigai.', 'Karthigai Krittika')

    rows.sort(key=lambda r: (r['date'], not r['is_major'], r['name']))
    for r in rows:
        r['slug'] = slug(r['name']) if r['key'] in ('ekadashi', 'purnima', 'amavasya') else r['key']
    return rows


if __name__ == '__main__':
    y0 = int(sys.argv[1]) if len(sys.argv) > 1 else date.today().year
    y1 = int(sys.argv[2]) if len(sys.argv) > 2 else y0
    json.dump(compute(y0, y1), sys.stdout, indent=1, ensure_ascii=False)
    sys.stdout.write('\n')
