/**
 * Allship UPS Quote Form — Frontend Orchestrator & Comparison Engine (V4)
 *
 * Implements:
 * - A. SERVICE_REGISTRY
 * - B. DirectionSelector
 * - C. ServiceTabsManager
 * - D. ShipmentTypeManager
 * - E. CountryCombobox (Tiếng Việt + IATA + English search)
 * - F. DestinationAddress (State / City cascade)
 * - G. PieceManager (Add / Remove, min 1, max 20)
 * - H. LiveMetricsEngine (Volumetric summary bar, ceilToHalf)
 * - I. QuoteComparisonEngine (Simultaneous 6 services, multipliers, Best Price tag)
 * - J. BookingModalManager (REST API lead submit, inline feedback)
 * - K. PiecesDetailModal (Kiện breakdown, DIM highlights, KPI footer)
 * - L. MobileStickyActionBar (Sticky footer bar, quick select)
 *
 * @package Allship_UPS_Quote
 */

(function(window, document) {
  'use strict';

  // ===== A. SERVICE REGISTRY =====
  const SERVICE_REGISTRY = {
    EXW: {
      code: 'EXW',
      name: 'Express Early',
      cat: 'parcel',
      has_document_split: true,
      icon: 'ph-globe',
      iconBg: '#FEF3C7',
      iconColor: '#D97706',
      desc_vi: 'Sớm · 1-2 ngày',
      eta_vi: 'Trước 9:00 AM',
      badge_text: 'Sớm',
      badge_color: 'amber'
    },
    XPR: {
      code: 'XPR',
      name: 'Express Plus',
      cat: 'parcel',
      has_document_split: true,
      icon: 'ph-rocket-launch',
      iconBg: '#EDE9FE',
      iconColor: '#7C3AED',
      desc_vi: 'Ưu tiên · 1-2 ngày',
      eta_vi: 'Trước 12:00 PM',
      badge_text: 'Ưu tiên',
      badge_color: 'purple'
    },
    WXS: {
      code: 'WXS',
      name: 'Express Saver',
      cat: 'parcel',
      has_document_split: true,
      icon: 'ph-airplane-tilt',
      iconBg: '#FEF3C7',
      iconColor: '#D97706',
      desc_vi: 'Nhanh nhất · 1-3 ngày',
      eta_vi: '1-3 ngày',
      badge_text: 'Phổ biến',
      badge_color: 'amber'
    },
    XPD: {
      code: 'XPD',
      name: 'Expedited',
      cat: 'parcel',
      has_document_split: false,
      icon: 'ph-truck',
      iconBg: '#E0E7FF',
      iconColor: '#4338CA',
      desc_vi: 'Tiết kiệm · 3-5 ngày',
      eta_vi: '3-5 ngày',
      badge_text: 'Giá tốt',
      badge_color: 'indigo'
    },
    WXP: {
      code: 'WXP',
      name: 'Express Freight',
      cat: 'freight',
      has_document_split: false,
      icon: 'ph-lightning',
      iconBg: '#FEE2E2',
      iconColor: '#DC2626',
      desc_vi: 'Hỏa tốc trên 70kg',
      eta_vi: '1-2 ngày (Trên 70kg)',
      badge_text: 'Hỏa tốc nặng',
      badge_color: 'rose'
    },
    WFM: {
      code: 'WFM',
      name: 'Freight Midday',
      cat: 'freight',
      has_document_split: false,
      icon: 'ph-crane',
      iconBg: '#ECFDF5',
      iconColor: '#059669',
      desc_vi: 'Tiêu chuẩn trên 70kg',
      eta_vi: '3-5 ngày (Pallet)',
      badge_text: 'Tiết kiệm nặng',
      badge_color: 'emerald'
    }
  };

  // ===== VIETNAMESE SEARCH ALIASES =====
  const VN_COUNTRY_ALIASES = {
    'my': 'US', 'hoa ky': 'US', 'hoa ki': 'US', 'united states': 'US', 'usa': 'US',
    'nhat': 'JP', 'nhat ban': 'JP', 'japan': 'JP',
    'han': 'KR', 'han quoc': 'KR', 'korea': 'KR', 'nam trieu tien': 'KR',
    'uc': 'AU', 'australia': 'AU',
    'duc': 'DE', 'germany': 'DE',
    'anh': 'GB', 'vuong quoc anh': 'GB', 'united kingdom': 'GB', 'uk': 'GB',
    'phap': 'FR', 'france': 'FR',
    'dai loan': 'TW', 'taiwan': 'TW',
    'trung quoc': 'CNN', 'china': 'CNN', 'dai luc': 'CNN',
    'singapore': 'SG', 'sing': 'SG',
    'thai lan': 'TH', 'thailand': 'TH',
    'ma lai': 'MY', 'malaysia': 'MY',
    'hong kong': 'HK',
    'nga': 'RU', 'russia': 'RU',
    'an do': 'IN', 'india': 'IN',
    'y': 'IT', 'italia': 'IT', 'italy': 'IT',
    'tay ban nha': 'ES', 'spain': 'ES',
    'ha lan': 'NL', 'netherlands': 'NL', 'holland': 'NL',
    'thuy si': 'CH', 'switzerland': 'CH',
    'thuy dien': 'SE', 'sweden': 'SE',
    'ba lan': 'PL', 'poland': 'PL',
    'philippines': 'PH', 'phi': 'PH',
    'indonesia': 'ID', 'indo': 'ID',
    'canada': 'CA'
  };

  function removeVietnameseTones(str) {
    if (!str) return '';
    str = String(str);
    str = str.replace(/à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ/g, 'a');
    str = str.replace(/è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ/g, 'e');
    str = str.replace(/ì|í|ị|ỉ|ĩ/g, 'i');
    str = str.replace(/ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ/g, 'o');
    str = str.replace(/ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ/g, 'u');
    str = str.replace(/ỳ|ý|ỵ|ỷ|ỹ/g, 'y');
    str = str.replace(/đ/g, 'd');
    return str.toLowerCase().trim();
  }

  function cleanCountryName(name) {
    if (!name) return '';
    return String(name).replace(/^["'\s]+|["'\s]+$/g, '').replace(/[*#]+$/g, '').trim();
  }

  // ===== DESTINATION ADDRESS DATA =====
  const MAJOR_CITIES_BY_STATE = {
    US: {
      CA: ['Los Angeles', 'San Francisco', 'San Diego', 'San Jose', 'Sacramento', 'Fresno', 'Long Beach', 'Oakland', 'Anaheim', 'Irvine'],
      NY: ['New York City', 'Buffalo', 'Rochester', 'Yonkers', 'Syracuse', 'Albany'],
      TX: ['Houston', 'Dallas', 'Austin', 'San Antonio', 'Fort Worth', 'El Paso', 'Arlington'],
      WA: ['Seattle', 'Spokane', 'Tacoma', 'Vancouver', 'Bellevue'],
      IL: ['Chicago', 'Aurora', 'Naperville', 'Joliet', 'Rockford'],
      FL: ['Miami', 'Orlando', 'Tampa', 'Jacksonville', 'Fort Lauderdale'],
      PA: ['Philadelphia', 'Pittsburgh', 'Allentown', 'Reading'],
      OH: ['Columbus', 'Cleveland', 'Cincinnati', 'Toledo'],
      GA: ['Atlanta', 'Augusta', 'Columbus', 'Savannah'],
      NC: ['Charlotte', 'Raleigh', 'Greensboro', 'Durham'],
      NJ: ['Newark', 'Jersey City', 'Paterson', 'Elizabeth'],
      VA: ['Virginia Beach', 'Norfolk', 'Richmond', 'Alexandria'],
      MA: ['Boston', 'Worcester', 'Springfield', 'Cambridge']
    },
    AU: {
      NSW: ['Sydney', 'Newcastle', 'Central Coast', 'Wollongong'],
      VIC: ['Melbourne', 'Geelong', 'Ballarat', 'Bendigo'],
      QLD: ['Brisbane', 'Gold Coast', 'Sunshine Coast', 'Cairns'],
      WA: ['Perth', 'Mandurah', 'Bunbury'],
      SA: ['Adelaide'],
      ACT: ['Canberra']
    },
    CA: {
      ON: ['Toronto', 'Ottawa', 'Mississauga', 'Brampton', 'Hamilton'],
      QC: ['Montreal', 'Quebec City', 'Laval', 'Gatineau'],
      BC: ['Vancouver', 'Surrey', 'Burnaby', 'Richmond', 'Victoria'],
      AB: ['Calgary', 'Edmonton']
    },
    JP: {
      '13': ['Tokyo', 'Shinjuku', 'Shibuya', 'Minato', 'Chiyoda'],
      '27': ['Osaka City', 'Sakai', 'Toyonaka'],
      '14': ['Yokohama', 'Kawasaki'],
      '23': ['Nagoya', 'Toyota'],
      '26': ['Kyoto City', 'Uji'],
      '28': ['Kobe', 'Himeji'],
      '40': ['Fukuoka City', 'Kitakyushu']
    },
    DE: {
      BE: ['Berlin'],
      BY: ['Munich', 'Nuremberg', 'Augsburg'],
      NW: ['Cologne', 'Düsseldorf', 'Dortmund', 'Bonn'],
      BW: ['Stuttgart', 'Mannheim', 'Karlsruhe'],
      HE: ['Frankfurt am Main', 'Wiesbaden'],
      HH: ['Hamburg']
    },
    GB: {
      EN: ['London', 'Birmingham', 'Manchester', 'Leeds', 'Liverpool'],
      SF: ['Glasgow', 'Edinburgh', 'Aberdeen'],
      WL: ['Cardiff', 'Swansea'],
      NB: ['Belfast', 'Derry']
    },
    KR: {
      _all: ['Seoul', 'Busan', 'Incheon', 'Daegu', 'Daejeon', 'Gwangju', 'Suwon', 'Ulsan']
    },
    SG: {
      _all: ['Singapore Central', 'Jurong', 'Woodlands', 'Tampines', 'Bedok', 'Changi']
    },
    FR: {
      _all: ['Paris', 'Marseille', 'Lyon', 'Toulouse', 'Nice', 'Nantes', 'Strasbourg']
    },
    TW: {
      _all: ['Taipei', 'New Taipei', 'Kaohsiung', 'Taichung', 'Tainan', 'Hsinchu']
    },
    MY: {
      _all: ['Kuala Lumpur', 'George Town', 'Johor Bahru', 'Petaling Jaya', 'Shah Alam', 'Kota Kinabalu', 'Kuching', 'Ipoh']
    },
    TH: {
      _all: ['Bangkok', 'Nonthaburi', 'Chiang Mai', 'Phuket', 'Pattaya', 'Samut Prakan', 'Hat Yai']
    },
    HK: {
      _all: ['Hong Kong Island', 'Kowloon', 'New Territories', 'Tsuen Wan', 'Sha Tin']
    }
  };

  // ===== EMBEDDED RATE TABLES (UPS VIETNAM NET RATES Q3-2026) =====
  const RATE_TABLES = {
    doc_rates: [
      {"weight_label":"UPS Envelope","rates":{"1":696035,"2":753389,"3":775448,"4":832803,"5":1030818,"6":980990,"7":1106597,"8":1207810,"9":1328747,"10":632712,"US5":1043793}},
      {"weight_label":"0.5","rates":{"1":696035,"2":753389,"3":775448,"4":832803,"5":1030818,"6":980990,"7":1106597,"8":1207810,"9":1328747,"10":632712,"US5":1043793}},
      {"weight_label":"1","rates":{"1":779601,"2":835658,"3":918185,"4":965677,"5":1258417,"6":1197170,"7":1384285,"8":1476933,"9":1608771,"10":734185,"US5":1274248}},
      {"weight_label":"1.5","rates":{"1":857717,"2":919742,"3":1063776,"4":1106857,"5":1487834,"6":1413611,"7":1655744,"8":1736195,"9":1890092,"10":812300,"US5":1506519}},
      {"weight_label":"2","rates":{"1":945694,"2":1000713,"3":1206513,"4":1231687,"5":1718289,"6":1629792,"7":1930318,"8":1999610,"9":2172970,"10":878738,"US5":1739828}},
      {"weight_label":"2.5","rates":{"1":1026665,"2":1089988,"3":1349250,"4":1375721,"5":1947445,"6":1842859,"7":2209302,"8":2264840,"9":2455847,"10":953740,"US5":1971841}},
      {"weight_label":"3","rates":{"1":1113085,"2":1183156,"3":1538700,"4":1572437,"5":2184389,"6":2080061,"7":2446505,"8":2509828,"9":2737168,"10":1029520,"US5":2211898}},
      {"weight_label":"3.5","rates":{"1":1198208,"2":1277881,"3":1675987,"4":1734638,"5":2414844,"6":2313371,"7":2683188,"8":2765196,"9":3020566,"10":1115941,"US5":2445207}},
      {"weight_label":"4","rates":{"1":1279179,"2":1375201,"3":1810419,"4":1875818,"5":2646336,"6":2548497,"7":2917795,"8":3019268,"9":3303184,"10":1196651,"US5":2679814}},
      {"weight_label":"4.5","rates":{"1":1365859,"2":1471225,"3":1946408,"4":2020111,"5":2878347,"6":2785439,"7":3154997,"8":3269965,"9":3586062,"10":1280476,"US5":2914681}},
      {"weight_label":"5","rates":{"1":1446311,"2":1528838,"3":2077985,"4":2162848,"5":3108802,"6":3021084,"7":3398947,"8":3522220,"9":3865047,"10":1364561,"US5":3147990}}
    ],
    nondoc_rates: [
      {"weight_label":"0.5","rates":{"1":760396,"2":830467,"3":918185,"4":959968,"5":609355,"6":589372,"7":656328,"8":1259196,"9":1398039,"10":749237,"US5":617141}},
      {"weight_label":"1","rates":{"1":843962,"2":916888,"3":1060922,"4":1093103,"5":738856,"6":722506,"7":808926,"8":1536624,"9":1715434,"10":833062,"US5":748199}},
      {"weight_label":"1.5","rates":{"1":927528,"2":1000713,"3":1203659,"4":1225977,"5":867578,"6":859533,"7":959708,"8":1816388,"9":2034385,"10":936092,"US5":878738}},
      {"weight_label":"2","rates":{"1":1008758,"2":1089988,"3":1346655,"4":1359111,"5":994225,"6":992408,"7":1113864,"8":2100822,"9":2357489,"10":1023811,"US5":1006682}},
      {"weight_label":"2.5","rates":{"1":1099590,"2":1173553,"3":1491987,"4":1492505,"5":1124245,"6":1123466,"7":1266462,"8":2381105,"9":2675403,"10":1115941,"US5":1138518}},
      {"weight_label":"3","rates":{"1":1165249,"2":1249075,"3":1637318,"4":1630830,"5":1248036,"6":1237655,"7":1417504,"8":2658533,"9":2953609,"10":1202101,"US5":1263867}},
      {"weight_label":"3.5","rates":{"1":1247518,"2":1324595,"3":1777459,"4":1769414,"5":1370789,"6":1341464,"7":1571660,"8":2938557,"9":3233892,"10":1288781,"US5":1388178}},
      {"weight_label":"4","rates":{"1":1316290,"2":1401413,"3":1917341,"4":1905144,"5":1494322,"6":1455394,"7":1724258,"8":3221435,"9":3513915,"10":1376759,"US5":1513008}},
      {"weight_label":"4.5","rates":{"1":1388956,"2":1474338,"3":2062933,"4":2057483,"5":1618114,"6":1569583,"7":1876856,"8":3498862,"9":3795496,"10":1465775,"US5":1638356}},
      {"weight_label":"5","rates":{"1":1461881,"2":1552455,"3":2205669,"4":2190617,"5":1741386,"6":1684550,"7":2031012,"8":3778886,"9":4070069,"10":1549860,"US5":1763445}},
      {"weight_label":"5.5","rates":{"1":1522091,"2":1603061,"3":2337506,"4":2323491,"5":1847530,"6":1795367,"7":2163627,"8":4016088,"9":4332185,"10":1622265,"US5":1870887}},
      {"weight_label":"6","rates":{"1":1581261,"2":1662232,"3":2471938,"4":2466228,"5":1952636,"6":1902808,"7":2296761,"8":4261336,"9":4594301,"10":1691039,"US5":1977290}},
      {"weight_label":"6.5","rates":{"1":1636020,"2":1722701,"3":2602477,"4":2610262,"5":2058780,"6":2012066,"7":2426781,"8":4498538,"9":4856158,"10":1763705,"US5":2084732}},
      {"weight_label":"7","rates":{"1":1691039,"2":1777459,"3":2736650,"4":2754297,"5":2163627,"6":2122363,"7":2558617,"8":4741191,"9":5112824,"10":1829364,"US5":2190876}},
      {"weight_label":"7.5","rates":{"1":1748652,"2":1830920,"3":2871081,"4":2897293,"5":2270030,"6":2230324,"7":2688638,"8":4981247,"9":5374941,"10":1902289,"US5":2298578}},
      {"weight_label":"8","rates":{"1":1795367,"2":1892687,"3":3007329,"4":3028611,"5":2366572,"6":2323752,"7":2809834,"8":5223899,"9":5637316,"10":1972360,"US5":2396417}},
      {"weight_label":"8.5","rates":{"1":1839226,"2":1947706,"3":3136312,"4":3161745,"5":2461557,"6":2402646,"7":2930771,"8":5463956,"9":5896318,"10":2041133,"US5":2492699}},
      {"weight_label":"9","rates":{"1":1884642,"2":2005059,"3":3269446,"4":3298513,"5":2525139,"6":2509569,"7":3053265,"8":5703754,"9":6156099,"10":2108349,"US5":2557061}},
      {"weight_label":"9.5","rates":{"1":1925646,"2":2060337,"3":3403878,"4":3434761,"5":2591836,"6":2592096,"7":3172645,"8":5943551,"9":6422108,"10":2179458,"US5":2624536}},
      {"weight_label":"10","rates":{"1":1962497,"2":2117951,"3":3538569,"4":3563483,"5":2656716,"6":2674104,"7":3294619,"8":6186203,"9":6681370,"10":2246933,"US5":2690195}},
      {"weight_label":"10.5","rates":{"1":1995457,"2":2178159,"3":3605267,"4":3661842,"5":2698240,"6":2749625,"7":3418411,"8":6428856,"9":6917274,"10":2333354,"US5":2732238}},
      {"weight_label":"11","rates":{"1":2029973,"2":2236034,"3":3678451,"4":3757605,"5":2743657,"6":2810354,"7":3528707,"8":6664760,"9":7162522,"10":2375915,"US5":2778173}},
      {"weight_label":"11.5","rates":{"1":2058780,"2":2293387,"3":3738660,"4":3852849,"5":2788554,"6":2874714,"7":3637707,"8":6907413,"9":7398426,"10":2416919,"US5":2823848}},
      {"weight_label":"12","rates":{"1":2094594,"2":2348406,"3":3808731,"4":3954062,"5":2831375,"6":2936740,"7":3749041,"8":7149805,"9":7638483,"10":2463633,"US5":2866929}},
      {"weight_label":"12.5","rates":{"1":2123142,"2":2406279,"3":3875946,"4":4047231,"5":2875493,"6":2997727,"7":3830531,"8":7387007,"9":7879578,"10":2503600,"US5":2911826}},
      {"weight_label":"13","rates":{"1":2151949,"2":2461038,"3":3935118,"4":4137025,"5":2896514,"6":3050929,"7":3914616,"8":7584243,"9":8079410,"10":2547459,"US5":2933107}},
      {"weight_label":"13.5","rates":{"1":2178159,"2":2521506,"3":3995327,"4":4217217,"5":2918314,"6":3101017,"7":3995327,"8":7783815,"9":8277684,"10":2589760,"US5":2954907}},
      {"weight_label":"14","rates":{"1":2206707,"2":2573410,"3":4050345,"4":4304675,"5":2940632,"6":3154738,"7":4076297,"8":7976640,"9":8480110,"10":2636734,"US5":2977745}},
      {"weight_label":"14.5","rates":{"1":2233178,"2":2621422,"3":4109257,"4":4373968,"5":2961394,"6":3205344,"7":4159344,"8":8176211,"9":8678644,"10":2680593,"US5":2998766}},
      {"weight_label":"15","rates":{"1":2259390,"2":2670991,"3":4171281,"4":4440665,"5":2983454,"6":3257249,"7":4241872,"8":8370592,"9":8871208,"10":2720299,"US5":3021084}},
      {"weight_label":"15.5","rates":{"1":2285342,"2":2710697,"3":4210729,"4":4475181,"5":3004216,"6":3289948,"7":4292738,"8":8554852,"9":9076489,"10":2767273,"US5":3042105}},
      {"weight_label":"16","rates":{"1":2314149,"2":2757411,"3":4254848,"4":4509697,"5":3026794,"6":3319274,"7":4343604,"8":8742226,"9":9274764,"10":2806720,"US5":3064943}},
      {"weight_label":"16.5","rates":{"1":2337506,"2":2801530,"3":4304416,"4":4544214,"5":3048334,"6":3350676,"7":4394729,"8":8930898,"9":9474335,"10":2853434,"US5":3086743}},
      {"weight_label":"17","rates":{"1":2366053,"2":2846686,"3":4345420,"4":4581584,"5":3070912,"6":3382078,"7":4446893,"8":9115159,"9":9670015,"10":2894438,"US5":3109581}},
      {"weight_label":"17.5","rates":{"1":2392265,"2":2893400,"3":4392134,"4":4616620,"5":3092453,"6":3412182,"7":4499057,"8":9299678,"9":9870884,"10":2939854,"US5":3131381}},
      {"weight_label":"18","rates":{"1":2412767,"2":2925062,"3":4426391,"4":4653991,"5":3115291,"6":3433982,"7":4529421,"8":9470183,"9":10001423,"10":2952312,"US5":3154478}},
      {"weight_label":"18.5","rates":{"1":2430934,"2":2964768,"3":4467395,"4":4685393,"5":3137350,"6":3453187,"7":4557708,"8":9638093,"9":10131702,"10":3000322,"US5":3177056}},
      {"weight_label":"19","rates":{"1":2444428,"2":3000322,"3":4499057,"4":4725878,"5":3159409,"6":3475506,"7":4590408,"8":9808339,"9":10266135,"10":3044441,"US5":3199116}},
      {"weight_label":"19.5","rates":{"1":2465190,"2":3031985,"3":4534871,"4":4760394,"5":3178614,"6":3496008,"7":4621551,"8":9973395,"9":10393818,"10":3093750,"US5":3218579}},
      {"weight_label":"20","rates":{"1":2483097,"2":3068837,"3":4573539,"4":4797766,"5":3202490,"6":3516770,"7":4651915,"8":10144419,"9":10522541,"10":3136312,"US5":3242975}},
      {"weight_label":"21-44","rates":{"1":112891,"2":141439,"3":204502,"4":210731,"5":153636,"6":150003,"7":155194,"8":485304,"9":499318,"10":130279,"US5":150003}},
      {"weight_label":"45-70","rates":{"1":107182,"2":138584,"3":192046,"4":199571,"5":153636,"6":150003,"7":155194,"8":482709,"9":489716,"10":125088,"US5":150003}},
      {"weight_label":"71-99","rates":{"1":98877,"2":130279,"3":180886,"4":178809,"5":153636,"6":150003,"7":155194,"8":464542,"9":472847,"10":104326,"US5":150003}},
      {"weight_label":"100-299","rates":{"1":98877,"2":126387,"3":178550,"4":175955,"5":153636,"6":150003,"7":155194,"8":459092,"9":467397,"10":104326,"US5":150003}},
      {"weight_label":"300-499","rates":{"1":96022,"2":123531,"3":171543,"4":173359,"5":153636,"6":150003,"7":155194,"8":450788,"9":454680,"10":101472,"US5":150003}},
      {"weight_label":"500-999","rates":{"1":96022,"2":123531,"3":171543,"4":173359,"5":153636,"6":150003,"7":155194,"8":450788,"9":454680,"10":101472,"US5":150003}},
      {">1000":"96022","weight_label":">1000","rates":{"1":96022,"2":123531,"3":171543,"4":173359,"5":153636,"6":150003,"7":155194,"8":450788,"9":454680,"10":101472,"US5":150003}}
    ],
    xpd_rates: [
      {"weight_label":"1","rates":{"1":641535,"2":751832,"3":891195,"4":918185,"5":625965,"6":643352,"7":702004,"8":1229092,"9":1406604,"10":641535,"US5":625965}},
      {"weight_label":"2","rates":{"1":766625,"2":893790,"3":1130993,"4":1141633,"5":842145,"6":883669,"7":966975,"8":1680917,"9":1933431,"10":788425,"US5":842145}},
      {"weight_label":"3","rates":{"1":885745,"2":1024070,"3":1375201,"4":1369752,"5":1057028,"6":1101666,"7":1230389,"8":2126775,"9":2422110,"10":925971,"US5":1057028}},
      {"weight_label":"4","rates":{"1":1000453,"2":1149159,"3":1610587,"4":1600466,"5":1265424,"6":1296048,"7":1496398,"8":2577044,"9":2881721,"10":1059884,"US5":1265424}},
      {"weight_label":"5","rates":{"1":1111009,"2":1272951,"3":1852720,"4":1839744,"5":1474858,"6":1499512,"7":1762666,"8":3023160,"9":3337441,"10":1193278,"US5":1474858}},
      {"weight_label":"6","rates":{"1":1201582,"2":1362744,"3":2076427,"4":2071497,"5":1653408,"6":1693894,"7":1993381,"8":3409328,"9":3767467,"10":1302017,"US5":1653408}},
      {"weight_label":"7","rates":{"1":1284888,"2":1457470,"3":2298837,"4":2313630,"5":1832478,"6":1889572,"7":2220981,"8":3792900,"9":4192563,"10":1408940,"US5":1832478}},
      {"weight_label":"8","rates":{"1":1364561,"2":1552195,"3":2525918,"4":2544085,"5":2004281,"6":2068641,"7":2438979,"8":4179326,"9":4622589,"10":1518717,"US5":2004281}},
      {"weight_label":"9","rates":{"1":1432037,"2":1644325,"3":2746252,"4":2770906,"5":2138453,"6":2234216,"7":2650229,"8":4562899,"9":5047684,"10":1623303,"US5":2138453}},
      {"weight_label":"10","rates":{"1":1491726,"2":1736715,"3":2972035,"4":2993575,"5":2250307,"6":2380586,"7":2859662,"8":4949067,"9":5478749,"10":1729967,"US5":2250307}},
      {"weight_label":"11","rates":{"1":1543112,"2":1833516,"3":3089858,"4":3156035,"5":2323491,"6":2501783,"7":3062607,"8":5331860,"9":5873221,"10":1829364,"US5":2323491}},
      {"weight_label":"12","rates":{"1":1591642,"2":1925646,"3":3199376,"4":3321351,"5":2397974,"6":2614415,"7":3253875,"8":5720103,"9":6263281,"10":1897099,"US5":2397974}},
      {"weight_label":"13","rates":{"1":1635761,"2":2018036,"3":3305260,"4":3474987,"5":2453252,"6":2715628,"7":3397909,"8":6067342,"9":6625054,"10":1961460,"US5":2453252}},
      {"weight_label":"14","rates":{"1":1677543,"2":2110165,"3":3402321,"4":3615907,"5":2490623,"6":2808537,"7":3538310,"8":6381364,"9":6953867,"10":2030233,"US5":2490623}},
      {"weight_label":"15","rates":{"1":1717251,"2":2190357,"3":3503535,"4":3730096,"5":2526697,"6":2899888,"7":3681825,"8":6696422,"9":7274375,"10":2094594,"US5":2526697}},
      {"weight_label":"16","rates":{"1":1758774,"2":2261206,"3":3574124,"4":3787969,"5":2563289,"6":2954907,"7":3770063,"8":6993573,"9":7605524,"10":2161032,"US5":2563289}},
      {"weight_label":"17","rates":{"1":1798480,"2":2334132,"3":3650164,"4":3848438,"5":2600660,"6":3010703,"7":3859856,"8":7292542,"9":7929146,"10":2229027,"US5":2600660}},
      {"weight_label":"18","rates":{"1":1833776,"2":2398234,"3":3718158,"4":3909425,"5":2638290,"6":3057158,"7":3931224,"8":7576198,"9":8201125,"10":2273404,"US5":2638290}},
      {"weight_label":"19","rates":{"1":1857652,"2":2460519,"3":3779405,"4":3969634,"5":2675662,"6":3094009,"7":3984426,"8":7846619,"9":8418345,"10":2343994,"US5":2675662}},
      {"weight_label":"20","rates":{"1":1886977,"2":2516576,"3":3841430,"4":4030362,"5":2712514,"6":3130602,"7":4037888,"8":8115224,"9":8628816,"10":2415103,"US5":2712514}},
      {"weight_label":"21-44","rates":{"1":85382,"2":115746,"3":172062,"4":176992,"5":148705,"6":140660,"7":146110,"8":388503,"9":409264,"10":100434,"US5":141698}},
      {"weight_label":"45-70","rates":{"1":81489,"2":113929,"3":161422,"4":167650,"5":148705,"6":140660,"7":146110,"8":385908,"9":401479,"10":96022,"US5":141698}},
      {"weight_label":"71-99","rates":{"1":75001,"2":106923,"3":152079,"4":150003,"5":148705,"6":140660,"7":146110,"8":371633,"9":387983,"10":80451,"US5":141698}},
      {"weight_label":"100-299","rates":{"1":75001,"2":103549,"3":150003,"4":147667,"5":148705,"6":140660,"7":146110,"8":367482,"9":383312,"10":80451,"US5":141698}},
      {"weight_label":"300-499","rates":{"1":72925,"2":101213,"3":144034,"4":145591,"5":148705,"6":140660,"7":146110,"8":360475,"9":372672,"10":78116,"US5":141698}},
      {"weight_label":"500-999","rates":{"1":72925,"2":101213,"3":144034,"4":145591,"5":148705,"6":140660,"7":146110,"8":360475,"9":372672,"10":78116,"US5":141698}},
      {">1000":"72925","weight_label":">1000","rates":{"1":72925,"2":101213,"3":144034,"4":145591,"5":148705,"6":140660,"7":146110,"8":360475,"9":372672,"10":78116,"US5":141698}}
    ],
    wfm_rates: [
      {"weight_label":"(kg)","rates":{"1":1,"2":2,"3":3,"4":4,"5":5,"6":6,"7":7,"8":8,"9":9,"10":10,"US5":0}},
      {"weight_label":"Minimum","rates":{"1":8424405,"2":11099839,"3":15411549,"4":15234581,"5":13089824,"6":12780281,"7":13222551,"8":39579093,"9":40286587,"10":8888644,"US5":12780281}},
      {"weight_label":"71-99","rates":{"1":118653,"2":156335,"3":217064,"4":214571,"5":184363,"6":180003,"7":186233,"8":557452,"9":567416,"10":125192,"US5":180003}},
      {"weight_label":"100-299","rates":{"1":118653,"2":151664,"3":214261,"4":211146,"5":184363,"6":180003,"7":186233,"8":550911,"9":560877,"10":125192,"US5":180003}},
      {"weight_label":"300-499","rates":{"1":115227,"2":148238,"3":205852,"4":208031,"5":184363,"6":180003,"7":186233,"8":540945,"9":545616,"10":121767,"US5":180003}},
      {"weight_label":"500-999","rates":{"1":115227,"2":148238,"3":205852,"4":208031,"5":184363,"6":180003,"7":186233,"8":540945,"9":545616,"10":121767,"US5":180003}},
      {">1000":"115227","weight_label":">1000","rates":{"1":115227,"2":148238,"3":205852,"4":208031,"5":184363,"6":180003,"7":186233,"8":540945,"9":545616,"10":121767,"US5":180003}}
    ]
  };

  // ===== STATE MANAGEMENT =====
  const state = {
    direction: 'export',
    service: 'WXS',
    shipmentType: 'nondocument',
    categoryFilter: 'all',
    selectedCountry: null,
    originProvince: 'TP. Hồ Chí Minh',
    selectedState: 'CA',
    selectedCity: 'Los Angeles',
    currentStates: [],
    currentCities: [],
    pieces: [],
    pieceIdCounter: 1,
    calculatedResults: [],
    mobileViewMode: 'compact',
    servicesAvailability: {},
    config: {
      pluginUrl: '',
      statesBaseUrl: '/wp-content/plugins/allship-ups-quote/public/assets/data/states/',
      citiesBaseUrl: '/wp-content/plugins/allship-ups-quote/public/assets/data/cities/',
      apiBase: '/wp-json/ups-quote/v1',
      nonce: '',
      currency: 'VND',
      COUNTRIES: [],
      VN_PROVINCES: [],
      POPULAR_IATA: ['US','JP','KR','AU','CA','DE','GB','FR','SG','TW'],
      dim_divisor: 5500,
      rounding_step: 0.5
    }
  };

  // ===== LAZY LOADED STATE & CITY CHUNK CACHE =====
  const stateChunkCache = {};
  let currentStateFetchIata = '';
  const cityChunkCache = {};
  let currentCityFetchKey = '';

  // ===== CORE UTILITIES =====
  function formatVND(amount) {
    if (amount === null || isNaN(amount)) return '0';
    return new Intl.NumberFormat('vi-VN').format(Math.round(amount));
  }

  function ceilToHalf(v, step) {
    const s = parseFloat(step || state.config.rounding_step) || 0.5;
    return Math.ceil(v / s) * s;
  }

  function escapeHTML(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showError(msg) {
    const el = document.getElementById('formError');
    const txt = document.getElementById('formErrorText');
    if (!el || !txt) return;
    txt.textContent = msg;
    el.classList.add('visible');
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  function hideError() {
    const el = document.getElementById('formError');
    if (el) el.classList.remove('visible');
  }

  // ===== B. DIRECTION SELECTOR =====
  function selectDirection(dir) {
    if (state.direction === dir) return;
    state.direction = dir;
    const isExport = dir === 'export';

    const btnExport = document.getElementById('btnDirExport');
    const btnImport = document.getElementById('btnDirImport');
    if (btnExport) {
      btnExport.classList.toggle('active', isExport);
      btnExport.setAttribute('aria-checked', isExport ? 'true' : 'false');
      const ind = btnExport.querySelector('.check-indicator');
      if (ind) ind.classList.toggle('opacity-0', !isExport);
    }
    if (btnImport) {
      btnImport.classList.toggle('active', !isExport);
      btnImport.setAttribute('aria-checked', !isExport ? 'true' : 'false');
      const ind = btnImport.querySelector('.check-indicator');
      if (ind) ind.classList.toggle('opacity-0', isExport);
    }

    // Hero badge
    const heroBadge = document.getElementById('heroDirectionBadge');
    const heroIcon = document.getElementById('heroDirectionIcon');
    const heroText = document.getElementById('heroDirectionText');
    if (heroText && heroIcon && heroBadge) {
      if (isExport) {
        heroIcon.className = 'ph-bold ph-airplane-takeoff';
        heroText.innerHTML = '<span>Việt Nam</span> <i class="ph-bold ph-arrow-right text-xs"></i> <span>Quốc tế</span>';
        heroBadge.className = 'inline-flex items-center gap-2 mt-2 text-xs md:text-sm font-bold text-brand-red bg-brand-red-light px-3.5 py-1 rounded-full transition-all';
      } else {
        heroIcon.className = 'ph-bold ph-airplane-landing text-blue-700';
        heroText.innerHTML = '<span>Quốc tế</span> <i class="ph-bold ph-arrow-right text-xs"></i> <span>Việt Nam</span>';
        heroBadge.className = 'inline-flex items-center gap-2 mt-2 text-xs md:text-sm font-bold text-blue-700 bg-blue-50 px-3.5 py-1 rounded-full transition-all';
      }
    }

    // Form field labels
    const originProvLabel = document.getElementById('originProvinceLabel');
    const destCountryLabel = document.getElementById('destCountryLabel');
    const destAddrTitle = document.getElementById('destAddressBlockTitle');

    if (originProvLabel) {
      originProvLabel.innerHTML = isExport
        ? 'Nơi gửi (Việt Nam) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>'
        : 'Nơi nhận (Việt Nam) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
    }
    if (destCountryLabel) {
      destCountryLabel.innerHTML = isExport
        ? 'Nước đến <span class="text-brand-red font-bold">*</span>'
        : 'Nước gửi (Quốc tế) <span class="text-brand-red font-bold">*</span>';
    }
    if (destAddrTitle) {
      destAddrTitle.textContent = isExport
        ? 'Chi tiết địa chỉ đến tại nước ngoài'
        : 'Chi tiết địa chỉ lấy hàng tại nước ngoài';
    }

    hideError();
    recalculateMetrics();

    // Re-render country options if searching
    const searchVal = document.getElementById('countrySearchInput')?.value;
    if (searchVal) filterCountries(searchVal);
    else renderCountryOptions(state.config.COUNTRIES);

    // Sync services availability for the chosen direction
    syncServicesAvailability(dir);
  }

  // ===== C. SERVICE TABS & AVAILABILITY MANAGER =====
  function adjustGridColumns(visibleCount) {
    const tabs = document.getElementById('serviceTabs');
    if (!tabs) return;

    tabs.classList.remove(
      'grid-cols-2', 'sm:grid-cols-3', 'lg:grid-cols-6',
      'grid-cols-auto-1', 'grid-cols-auto-2', 'grid-cols-auto-3',
      'grid-cols-auto-4', 'grid-cols-auto-5', 'grid-cols-auto-6',
      'max-w-md', 'max-w-2xl', 'max-w-3xl'
    );

    if (visibleCount <= 1) {
      tabs.classList.add('grid-cols-auto-1', 'max-w-md');
    } else if (visibleCount === 2) {
      tabs.classList.add('grid-cols-auto-2', 'max-w-2xl');
    } else if (visibleCount === 3) {
      tabs.classList.add('grid-cols-auto-3', 'max-w-3xl');
    } else if (visibleCount === 4) {
      tabs.classList.add('grid-cols-auto-4');
    } else if (visibleCount === 5) {
      tabs.classList.add('grid-cols-auto-5');
    } else {
      tabs.classList.add('grid-cols-2', 'sm:grid-cols-3', 'lg:grid-cols-6', 'grid-cols-auto-6');
    }
  }

  function updateCategoryTabCounts() {
    let totalAvail = 0;
    let parcelAvail = 0;
    let freightAvail = 0;

    const cards = document.querySelectorAll('.service-card-item');
    cards.forEach(card => {
      const code = card.dataset.code;
      const cat = card.dataset.cat;
      const s = state.servicesAvailability[code];
      const isEnabled = s ? s.enabled : !card.classList.contains('is-disabled');
      if (isEnabled) {
        totalAvail++;
        if (cat === 'parcel') parcelAvail++;
        else if (cat === 'freight') freightAvail++;
      }
    });

    const tabAll = document.querySelector('.cat-filter-tab[data-cat="all"]');
    if (tabAll) tabAll.textContent = `Tất cả (${totalAvail})`;

    const tabParcel = document.querySelector('.cat-filter-tab[data-cat="parcel"]');
    if (tabParcel) {
      tabParcel.textContent = `Bưu kiện dưới 70kg (${parcelAvail})`;
      if (parcelAvail === 0) {
        tabParcel.classList.add('opacity-40', 'pointer-events-none');
      } else {
        tabParcel.classList.remove('opacity-40', 'pointer-events-none');
      }
    }

    const tabFreight = document.querySelector('.cat-filter-tab[data-cat="freight"]');
    if (tabFreight) {
      tabFreight.textContent = `Hàng nặng trên 70kg (${freightAvail})`;
      if (freightAvail === 0) {
        tabFreight.classList.add('opacity-40', 'pointer-events-none');
      } else {
        tabFreight.classList.remove('opacity-40', 'pointer-events-none');
      }
    }

    const countNotice = document.getElementById('serviceCountNotice');
    if (countNotice) {
      countNotice.textContent = `(${totalAvail} gói khả dụng)`;
    }
  }

  function updateServicesUI(servicesList) {
    if (!Array.isArray(servicesList)) return;

    let hasEnabled = false;
    let firstEnabled = null;

    servicesList.forEach(s => {
      state.servicesAvailability[s.code] = s;
      if (s.enabled) {
        hasEnabled = true;
        if (!firstEnabled) firstEnabled = s.code;
      }
    });

    document.querySelectorAll('.service-card-item').forEach(card => {
      const code = card.dataset.code;
      const s = state.servicesAvailability[code];
      if (!s) return;

      const badgeEl = card.querySelector('.service-status-badge');
      if (s.enabled) {
        card.classList.remove('is-disabled', 'opacity-45', 'cursor-not-allowed', 'hidden');
        card.classList.add('cursor-pointer');
        card.setAttribute('tabindex', '0');
        card.removeAttribute('title');
        card.style.display = 'flex';
        if (badgeEl) {
          badgeEl.className = 'font-bold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded service-status-badge';
          badgeEl.textContent = 'Khả dụng';
          badgeEl.removeAttribute('title');
        }
      } else {
        card.classList.add('is-disabled', 'opacity-45', 'cursor-not-allowed', 'hidden');
        card.classList.remove('cursor-pointer', 'active');
        card.setAttribute('aria-checked', 'false');
        card.setAttribute('tabindex', '-1');
        card.style.display = 'none';
        const reason = s.reason || (s.admin_disabled ? 'Admin tạm tắt dịch vụ này' : 'Chưa có bảng giá cho dịch vụ này');
        card.setAttribute('title', reason);
        if (badgeEl) {
          if (s.admin_disabled) {
            badgeEl.className = 'font-bold text-amber-800 bg-amber-100 px-1.5 py-0.5 rounded service-status-badge';
            badgeEl.textContent = 'Tạm tắt';
          } else {
            badgeEl.className = 'font-bold text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded service-status-badge';
            badgeEl.textContent = 'Chưa có giá';
          }
          badgeEl.setAttribute('title', reason);
        }
      }
    });

    // Update notice banner if no services are available
    const notice = document.getElementById('noServicesAvailableNotice');
    if (notice) {
      notice.classList.toggle('hidden', hasEnabled);
    }

    // Update category tab counts
    updateCategoryTabCounts();

    // Re-apply current category filter to hide/show and adjust columns
    const currentBtn = document.querySelector(`.cat-filter-tab[data-cat="${state.categoryFilter}"]`) || document.querySelector('.cat-filter-tab[data-cat="all"]');
    filterCategory(state.categoryFilter, currentBtn);

    // Update calculate button state
    const btnCalc = document.getElementById('btnCalculate');
    if (btnCalc) {
      if (!hasEnabled) {
        btnCalc.disabled = true;
        btnCalc.classList.add('opacity-50', 'cursor-not-allowed');
        btnCalc.setAttribute('title', 'Hiện không có dịch vụ nào khả dụng');
      } else {
        btnCalc.disabled = false;
        btnCalc.classList.remove('opacity-50', 'cursor-not-allowed');
        btnCalc.removeAttribute('title');
      }
    }

    // If current selected service is disabled or empty, switch to first available enabled
    const currentSvc = state.servicesAvailability[state.service];
    if ((!currentSvc || !currentSvc.enabled) && firstEnabled) {
      selectService(firstEnabled);
    } else if (!hasEnabled) {
      state.service = '';
    }
  }

  function syncServicesAvailability(dir) {
    dir = dir || state.direction;
    if (!state.config.apiBase) return;

    fetch(`${state.config.apiBase}/services?direction=${dir}`, {
      headers: { 'X-WP-Nonce': state.config.nonce || '' }
    })
    .then(res => res.json())
    .then(res => {
      if (res && res.success && Array.isArray(res.data)) {
        updateServicesUI(res.data);
      }
    })
    .catch(err => {
      console.warn('UPS syncServicesAvailability error:', err);
    });
  }

  function filterCategory(cat, btn) {
    state.categoryFilter = cat;
    document.querySelectorAll('.cat-filter-tab').forEach(t => {
      t.classList.remove('active');
      t.setAttribute('aria-selected', 'false');
    });
    if (btn) {
      btn.classList.add('active');
      btn.setAttribute('aria-selected', 'true');
    }

    const cards = document.querySelectorAll('.service-card-item');
    let visibleCount = 0;
    cards.forEach(c => {
      const code = c.getAttribute('data-code');
      const cardCat = c.getAttribute('data-cat');
      const s = state.servicesAvailability[code];
      const isEnabled = s ? s.enabled : !c.classList.contains('is-disabled');

      // Card is only visible if enabled AND matches category filter
      if (isEnabled && (cat === 'all' || cardCat === cat)) {
        c.style.display = 'flex';
        c.classList.remove('hidden');
        visibleCount++;
      } else {
        c.style.display = 'none';
        c.classList.add('hidden');
      }
    });

    adjustGridColumns(visibleCount);

    const countNotice = document.getElementById('serviceCountNotice');
    if (countNotice) {
      countNotice.textContent = '(' + visibleCount + ' gói khả dụng)';
    }

    // If current selected service is hidden under this filter, switch to first visible
    const currentCard = document.querySelector(`.service-card-item[data-code="${state.service}"]`);
    if (currentCard && currentCard.style.display === 'none') {
      const firstVisible = document.querySelector('.service-card-item:not(.hidden):not(.is-disabled)');
      if (firstVisible && firstVisible.dataset.code) {
        selectService(firstVisible.dataset.code);
      }
    }

    // If result section is visible, re-render result grid
    const resultSec = document.getElementById('resultSection');
    if (resultSec && resultSec.style.display !== 'none' && state.calculatedResults.length > 0) {
      executeCalculation();
    }
  }

  function selectService(code) {
    const serviceConf = SERVICE_REGISTRY[code];
    if (!serviceConf) return;

    // Guard: Prevent selecting disabled services
    const avail = state.servicesAvailability[code];
    if (avail && !avail.enabled) {
      const reason = avail.reason || 'Dịch vụ này hiện chưa có bảng giá khả dụng. Vui lòng chọn gói khác hoặc liên hệ Hotline 1900 252 338.';
      showError(reason);
      return;
    }

    state.service = code;
    document.querySelectorAll('.service-card-item').forEach(card => {
      const isCurrent = card.dataset.code === code;
      card.classList.toggle('active', isCurrent);
      card.setAttribute('aria-checked', isCurrent ? 'true' : 'false');
    });

    // Update service name notice in shipment type toggle
    const currentNameSpan = document.getElementById('currentServiceNameType');
    if (currentNameSpan) currentNameSpan.textContent = serviceConf.name;

    // Show/hide shipment type toggle based on has_document_split
    const typeRow = document.getElementById('shipmentTypeRow');
    if (typeRow) {
      if (serviceConf.has_document_split) {
        typeRow.style.display = 'block';
      } else {
        typeRow.style.display = 'none';
        state.shipmentType = 'nondocument';
      }
    }

    // Refresh country options dropdown badges
    const searchInput = document.getElementById('countrySearchInput');
    if (searchInput && searchInput.value) filterCountries(searchInput.value);
    else renderCountryOptions(state.config.COUNTRIES);

    recalculateMetrics();
    hideError();
    updateStickyBar();
  }

  // ===== D. SHIPMENT TYPE MANAGER =====
  function setShipmentType(type) {
    state.shipmentType = type;
    const isNon = type === 'nondocument';
    const optNon = document.getElementById('optNondoc');
    const optDoc = document.getElementById('optDoc');

    if (optNon) {
      optNon.classList.toggle('active', isNon);
      optNon.setAttribute('aria-checked', isNon ? 'true' : 'false');
      const ind = optNon.querySelector('.type-check');
      if (ind) ind.classList.toggle('opacity-0', !isNon);
    }
    if (optDoc) {
      optDoc.classList.toggle('active', !isNon);
      optDoc.setAttribute('aria-checked', !isNon ? 'true' : 'false');
      const ind = optDoc.querySelector('.type-check');
      if (ind) ind.classList.toggle('opacity-0', isNon);
    }

    recalculateMetrics();
    hideError();
  }

  // ===== POPULAR DATA & ALIASES =====
  const VN_POPULAR_PROVINCES = [
    { name: 'TP. Hồ Chí Minh', code: 'SGN' },
    { name: 'Hà Nội', code: 'HAN' },
    { name: 'Đà Nẵng', code: 'DAD' },
    { name: 'Bình Dương', code: 'BDG' },
    { name: 'Hải Phòng', code: 'HPH' },
    { name: 'Đồng Nai', code: 'DNI' },
    { name: 'Bắc Ninh', code: 'BNH' },
    { name: 'Cần Thơ', code: 'VCA' }
  ];

  const VN_PROVINCE_ALIASES = {
    'hcm': 'TP. Hồ Chí Minh',
    'tphcm': 'TP. Hồ Chí Minh',
    'sg': 'TP. Hồ Chí Minh',
    'saigon': 'TP. Hồ Chí Minh',
    'hn': 'Hà Nội',
    'hanoi': 'Hà Nội',
    'dn': 'Đà Nẵng',
    'danang': 'Đà Nẵng',
    'hp': 'Hải Phòng',
    'haiphong': 'Hải Phòng',
    'bd': 'Bình Dương',
    'binhduong': 'Bình Dương',
    'ct': 'Cần Thơ',
    'cantho': 'Cần Thơ',
    'dongnai': 'Đồng Nai',
    'bacninh': 'Bắc Ninh'
  };

  const POPULAR_STATES_BY_COUNTRY = {
    US: ['CA', 'TX', 'NY', 'FL', 'WA', 'IL', 'PA', 'GA'],
    CA: ['ON', 'BC', 'QC', 'AB'],
    AU: ['NSW', 'VIC', 'QLD', 'WA']
  };

  const POPULAR_CITIES_BY_STATE = {
    'US-CA': ['Los Angeles', 'San Francisco', 'San Diego', 'San Jose', 'Sacramento', 'Oakland'],
    'US-TX': ['Houston', 'Dallas', 'Austin', 'San Antonio', 'Fort Worth', 'El Paso'],
    'US-NY': ['New York', 'Buffalo', 'Rochester', 'Yonkers', 'Syracuse', 'Albany'],
    'US-FL': ['Miami', 'Orlando', 'Tampa', 'Jacksonville', 'Fort Lauderdale'],
    'US-WA': ['Seattle', 'Spokane', 'Tacoma', 'Vancouver', 'Bellevue'],
    'CA-AB': ['Calgary', 'Edmonton', 'Red Deer', 'Lethbridge'],
    'CA-BC': ['Vancouver', 'Surrey', 'Burnaby', 'Richmond', 'Victoria'],
    'CA-ON': ['Toronto', 'Ottawa', 'Mississauga', 'Brampton', 'Hamilton'],
    'CA-QC': ['Montreal', 'Quebec City', 'Laval', 'Gatineau'],
    'AU-NSW': ['Sydney', 'Newcastle', 'Central Coast', 'Wollongong'],
    'AU-VIC': ['Melbourne', 'Geelong', 'Ballarat', 'Bendigo'],
    'AU-QLD': ['Brisbane', 'Gold Coast', 'Sunshine Coast', 'Cairns']
  };

  // ===== ORIGIN PROVINCE COMBOBOX =====
  function toggleOriginDropdown(isOpen) {
    const dd = document.getElementById('originDropdown');
    const display = document.getElementById('originProvinceDisplay');
    const wrap = document.getElementById('originCombobox');
    if (!dd) return;

    if (isOpen) {
      toggleCountryDropdown(false);
      toggleStateDropdown(false);
      toggleCityDropdown(false);
      dd.classList.add('open');
      if (wrap) wrap.classList.add('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'true');
      const searchInput = document.getElementById('originSearchInput');
      if (searchInput) {
        searchInput.value = '';
        renderOriginOptions(state.config.VN_PROVINCES);
        setTimeout(() => searchInput.focus(), 60);
      }
    } else {
      dd.classList.remove('open');
      if (wrap) wrap.classList.remove('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'false');
    }
  }

  function renderOriginOptions(list, isSearching = false) {
    const container = document.getElementById('originOptionsList');
    if (!container) return;

    if (!list || list.length === 0) {
      container.innerHTML = '<div class="p-4 text-center text-slate-400 text-xs">Không tìm thấy tỉnh thành</div>';
      return;
    }

    const popularNames = VN_POPULAR_PROVINCES.map(p => p.name);
    let html = '';

    if (!isSearching) {
      const popular = list.filter(p => popularNames.includes(p));
      if (popular.length > 0) {
        html += '<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60 flex items-center gap-1"><i class="ph ph-star"></i> Tỉnh / Thành phổ biến</div>';
        html += popular.map(p => renderOriginOption(p)).join('');
        html += `<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60">Tất cả tỉnh thành (${list.length})</div>`;
      }
    }

    html += list.map(p => renderOriginOption(p)).join('');
    container.innerHTML = html;
  }

  function renderOriginOption(p) {
    const isSelected = state.originProvince === p;
    const pop = VN_POPULAR_PROVINCES.find(item => item.name === p);
    const code = pop ? pop.code : 'VN';
    return `
      <div class="origin-option flex items-center justify-between px-3.5 py-2.5 cursor-pointer text-[13px] border-b border-slate-100 last:border-b-0 hover:bg-slate-50 transition-colors ${isSelected ? 'bg-slate-50 font-bold' : ''}"
           onclick="UPSQuote.selectOriginProvince('${escapeHTML(p)}')">
        <div class="flex items-center gap-2.5">
          <span class="${isSelected ? 'font-bold text-navy-900' : 'text-slate-700'}">${escapeHTML(p)}</span>
          <span class="text-[10px] font-mono font-bold text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded tracking-wide">${code}</span>
        </div>
        ${isSelected ? '<i class="ph-bold ph-check text-brand-red text-sm" aria-hidden="true"></i>' : ''}
      </div>
    `;
  }

  function selectOriginProvince(provinceName) {
    state.originProvince = provinceName;
    const hidden = document.getElementById('originProvince');
    const display = document.getElementById('originProvinceDisplay');
    if (hidden) hidden.value = provinceName;
    if (display) display.value = provinceName;
    toggleOriginDropdown(false);
  }

  function filterOriginProvinces(term) {
    const raw = (term || '').trim().toLowerCase();
    if (!raw) {
      renderOriginOptions(state.config.VN_PROVINCES);
      return;
    }
    const norm = removeVietnameseTones(raw);
    const aliasMatch = VN_PROVINCE_ALIASES[norm] || VN_PROVINCE_ALIASES[raw];

    const filtered = (state.config.VN_PROVINCES || []).filter(p => {
      const normP = removeVietnameseTones(p).toLowerCase();
      const matchText = normP.includes(norm);
      const matchAlias = aliasMatch && p === aliasMatch;
      return matchText || matchAlias;
    });
    renderOriginOptions(filtered, true);
  }

  // ===== E. COUNTRY COMBOBOX =====
  function toggleCountryDropdown(isOpen) {
    const dd = document.getElementById('countryDropdown');
    const display = document.getElementById('destCountryDisplay');
    const wrap = document.getElementById('countryCombobox');
    if (!dd) return;

    if (isOpen) {
      toggleOriginDropdown(false);
      toggleStateDropdown(false);
      toggleCityDropdown(false);
      dd.classList.add('open');
      if (wrap) wrap.classList.add('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'true');
      const searchInput = document.getElementById('countrySearchInput');
      if (searchInput) {
        searchInput.value = '';
        renderCountryOptions(state.config.COUNTRIES);
        setTimeout(() => searchInput.focus(), 60);
      }
    } else {
      dd.classList.remove('open');
      if (wrap) wrap.classList.remove('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'false');
    }
  }

  function getZoneForService(country) {
    if (!country) return 0;
    const code = state.service;
    if (country.wxs !== undefined || country.xpd !== undefined || country.wfm !== undefined) {
      if (code === 'WXS' || code === 'EXW' || code === 'XPR') return country.wxs || 0;
      if (code === 'XPD') return country.xpd || 0;
      if (code === 'WFM' || code === 'WXP') return country.wfm || 0;
      return country.wxs || 0;
    }
    // Zero-Leakage: zones resolved securely on server via /calculate
    return 1;
  }

  function filterCountries(term) {
    const raw = (term || '').trim();
    if (!raw) {
      renderCountryOptions(state.config.COUNTRIES);
      return;
    }

    const normTerm = removeVietnameseTones(raw);
    const iataAlias = VN_COUNTRY_ALIASES[normTerm];

    const filtered = state.config.COUNTRIES.filter(c => {
      const cleanName = cleanCountryName(c.name);
      const normName = removeVietnameseTones(cleanName);
      const matchName = normName.includes(normTerm);
      const matchIata = c.iata.toLowerCase().includes(raw.toLowerCase());
      const matchAlias = iataAlias && c.iata.toUpperCase() === iataAlias.toUpperCase();
      return matchName || matchIata || matchAlias;
    });

    renderCountryOptions(filtered, true);
  }

  function renderCountryOptions(list, isSearching = false) {
    const container = document.getElementById('countryOptionsList');
    if (!container) return;

    if (!list || list.length === 0) {
      container.innerHTML = '<div class="p-4 text-center text-slate-400 text-xs">Không tìm thấy quốc gia</div>';
      return;
    }

    let html = '';
    if (!isSearching) {
      const popularIatas = state.config.POPULAR_IATA || ['US', 'AU', 'CA', 'JP', 'KR', 'TW', 'SG', 'MY', 'TH', 'GB', 'DE', 'FR', 'HK'];
      const popular = popularIatas
        .map(code => list.find(c => c.iata === code))
        .filter(Boolean);
      if (popular.length > 0) {
        html += '<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60 flex items-center gap-1"><i class="ph ph-star"></i> Tuyến phổ biến</div>';
        html += popular.map(c => renderCountryOption(c)).join('');
        html += '<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60">Tất cả quốc gia</div>';
      }
    }

    html += list.map(c => renderCountryOption(c)).join('');
    container.innerHTML = html;
  }

  function renderCountryOption(c) {
    const hasExplicitZones = c.wxs !== undefined || c.xpd !== undefined || c.wfm !== undefined;
    const zone = getZoneForService(c);
    const isAvailable = hasExplicitZones ? (zone > 0) : true;
    const isSelected = state.selectedCountry && state.selectedCountry.iata === c.iata;
    const displayName = cleanCountryName(c.name);
    return `
      <div class="country-option flex items-center justify-between px-3.5 py-2.5 cursor-pointer text-[13px] border-b border-slate-100 last:border-b-0 hover:bg-slate-50 transition-colors ${isSelected ? 'bg-slate-50 font-bold' : ''} ${!isAvailable ? 'opacity-40 pointer-events-none cursor-not-allowed' : ''}"
           onclick="UPSQuote.selectCountry('${c.iata}')" role="option" aria-selected="${isSelected ? 'true' : 'false'}">
        <div class="flex items-center gap-2.5">
          <span class="${isSelected ? 'font-bold text-navy-900' : 'text-slate-700'}">${displayName}</span>
          <span class="text-[10px] font-mono font-bold text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded tracking-wide">${c.iata}</span>
        </div>
        ${!isAvailable
          ? '<span class="text-[10px] font-bold text-slate-400 bg-slate-100 px-2 py-0.5 rounded-full">Chưa hỗ trợ</span>'
          : (isSelected ? '<i class="ph-bold ph-check text-brand-red text-sm" aria-hidden="true"></i>' : '')}
      </div>
    `;
  }

  function selectCountry(iata) {
    const country = state.config.COUNTRIES.find(c => c.iata === iata);
    if (!country) return;
    const hasExplicitZones = country.wxs !== undefined || country.xpd !== undefined || country.wfm !== undefined;
    const zone = getZoneForService(country);
    if (hasExplicitZones && zone <= 0) return;

    const previousIata = state.selectedCountry ? state.selectedCountry.iata : '';
    state.selectedCountry = country;
    const display = document.getElementById('destCountryDisplay');
    if (display) {
      display.value = `${cleanCountryName(country.name)} (${country.iata})`;
    }

    toggleCountryDropdown(false);

    // Reset destination address fields if country changed to prevent leftover data
    if (previousIata && previousIata !== country.iata) {
      const zipInput = document.getElementById('destZipcode');
      if (zipInput) zipInput.value = '';
      const cityInput = document.getElementById('destCity');
      if (cityInput) cityInput.value = '';
      const cityDisplay = document.getElementById('destCityDisplay');
      if (cityDisplay) cityDisplay.value = '';
      const stateHidden = document.getElementById('destState');
      if (stateHidden) stateHidden.value = '';
      const stateDisplay = document.getElementById('destStateDisplay');
      if (stateDisplay) stateDisplay.value = '';
      state.selectedState = '';
    }

    updateDestinationAddressFields(country.iata);
    recalculateMetrics();
    hideError();
  }

  // ===== F. DESTINATION ADDRESS (STATE/CITY CASCADE) =====
  async function getStatesForCountry(iata) {
    if (!iata) return [];
    if (stateChunkCache[iata]) {
      return stateChunkCache[iata];
    }
    if (typeof window !== 'undefined' && window.STATES_BY_COUNTRY && window.STATES_BY_COUNTRY[iata]) {
      stateChunkCache[iata] = window.STATES_BY_COUNTRY[iata];
      return stateChunkCache[iata];
    }
    if (window.UPSQuote?.states?.[iata]) {
      stateChunkCache[iata] = window.UPSQuote.states[iata];
      return stateChunkCache[iata];
    }

    const baseUrl = state.config.statesBaseUrl || '/wp-content/plugins/allship-ups-quote/public/assets/data/states/';
    try {
      const res = await fetch(`${baseUrl}${iata}.json`);
      if (res.ok) {
        const data = await res.json();
        if (Array.isArray(data)) {
          stateChunkCache[iata] = data;
          return data;
        }
      }
    } catch (e) {
      // Fallback empty
    }
    stateChunkCache[iata] = [];
    return [];
  }

  function applyStatesToDOM(iata, states) {
    const stateDisplay = document.getElementById('destStateDisplay');
    const stateHidden = document.getElementById('destState');

    state.currentStates = states;

    if (states && states.length > 0) {
      if (stateHidden) {
        stateHidden.innerHTML = states.map(s => `<option value="${escapeHTML(s.code || s.name)}">${escapeHTML(s.name)}</option>`).join('');
      }
      if (stateDisplay) {
        if (stateDisplay.parentElement && stateDisplay.parentElement.classList) {
          stateDisplay.parentElement.classList.remove('opacity-60', 'pointer-events-none');
        }
        state.selectedState = '';
        if (stateHidden) stateHidden.value = '';
        stateDisplay.value = '';
        stateDisplay.placeholder = '— Chọn Bang / Tỉnh / Khu vực —';
        renderStateOptions(states);
        onStateChange('');
      }
      const cityDisplay = document.getElementById('destCityDisplay');
      if (cityDisplay) {
        cityDisplay.value = '';
        cityDisplay.placeholder = '— Chọn Bang / Tỉnh trước —';
        if (cityDisplay.parentElement && cityDisplay.parentElement.classList) {
          cityDisplay.parentElement.classList.add('opacity-60', 'pointer-events-none');
        }
      }
    } else {
      if (stateHidden) {
        stateHidden.innerHTML = '<option value="">— Không phân bang / tỉnh —</option>';
      }
      if (stateDisplay) {
        state.selectedState = '';
        if (stateHidden) stateHidden.value = '';
        stateDisplay.value = '';
        stateDisplay.placeholder = '— Không phân bang / tỉnh —';
        if (stateDisplay.parentElement && stateDisplay.parentElement.classList) {
          stateDisplay.parentElement.classList.add('opacity-60', 'pointer-events-none');
        }
      }
      renderStateOptions([]);
      const cityDisplay = document.getElementById('destCityDisplay');
      if (cityDisplay) {
        cityDisplay.value = '';
        cityDisplay.placeholder = '— Chọn Thành phố —';
        if (cityDisplay.parentElement && cityDisplay.parentElement.classList) {
          cityDisplay.parentElement.classList.remove('opacity-60', 'pointer-events-none');
        }
      }
      onStateChange('');
    }
  }

  function updateDestinationAddressFields(iata) {
    const stateLabel = document.getElementById('destStateLabel');
    const stateDisplay = document.getElementById('destStateDisplay');
    const cityLabel = document.getElementById('destCityLabel');
    const zipLabel = document.getElementById('destZipcodeLabel');
    const zipInput = document.getElementById('destZipcode');

    if (iata === 'US') {
      if (stateLabel) stateLabel.innerHTML = 'Tiểu bang (State) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (cityLabel) cityLabel.innerHTML = 'Thành phố (City) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipLabel) zipLabel.innerHTML = 'Mã bưu chính (Zipcode) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipInput) zipInput.placeholder = 'VD: 90210, 10001, 75001...';
    } else if (iata === 'CA') {
      if (stateLabel) stateLabel.innerHTML = 'Tỉnh / Bang (Province) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (cityLabel) cityLabel.innerHTML = 'Thành phố (City) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipLabel) zipLabel.innerHTML = 'Mã Postal Code <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipInput) zipInput.placeholder = 'VD: M5V 2T6, V6B 2W9...';
    } else if (iata === 'AU') {
      if (stateLabel) stateLabel.innerHTML = 'Tiểu bang (State) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (cityLabel) cityLabel.innerHTML = 'Thành phố / Ngoại ô <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipLabel) zipLabel.innerHTML = 'Mã Postcode <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipInput) zipInput.placeholder = 'VD: 2000, 3000, 4000...';
    } else if (iata === 'JP') {
      if (stateLabel) stateLabel.innerHTML = 'Tỉnh / Huyện (Prefecture) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (cityLabel) cityLabel.innerHTML = 'Thành phố / Quận <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipLabel) zipLabel.innerHTML = 'Mã Bưu chính (〒) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipInput) zipInput.placeholder = 'VD: 100-0001, 530-0001...';
    } else if (iata === 'DE') {
      if (stateLabel) stateLabel.innerHTML = 'Bang (Bundesland) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (cityLabel) cityLabel.innerHTML = 'Thành phố (Stadt) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipLabel) zipLabel.innerHTML = 'Mã PLZ <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipInput) zipInput.placeholder = 'VD: 10115, 80331...';
    } else {
      if (stateLabel) stateLabel.innerHTML = 'Bang / Tỉnh / Khu vực <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (cityLabel) cityLabel.innerHTML = 'Thành phố <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipLabel) zipLabel.innerHTML = 'Mã bưu chính (Zipcode) <span class="text-[11px] font-medium text-slate-400 normal-case tracking-normal">(tuỳ chọn)</span>';
      if (zipInput) zipInput.placeholder = 'VD: 90210, 10001...';
    }

    // Synchronous fast path if already in cache or in window.STATES_BY_COUNTRY
    const cachedStates = (stateChunkCache[iata])
      || (typeof window !== 'undefined' && window.STATES_BY_COUNTRY && window.STATES_BY_COUNTRY[iata])
      || (window.UPSQuote?.states?.[iata]);

    if (cachedStates) {
      applyStatesToDOM(iata, cachedStates);
      return;
    }

    if (stateDisplay) {
      stateDisplay.placeholder = 'Đang tải danh sách...';
    }

    currentStateFetchIata = iata;
    getStatesForCountry(iata).then(states => {
      if (currentStateFetchIata === iata) {
        applyStatesToDOM(iata, states);
      }
    });
  }

  // ===== DESTINATION STATE COMBOBOX =====
  function toggleStateDropdown(isOpen) {
    const dd = document.getElementById('stateDropdown');
    const display = document.getElementById('destStateDisplay');
    const wrap = document.getElementById('stateCombobox');
    if (!dd) return;

    if (isOpen) {
      if (!state.currentStates || state.currentStates.length === 0) return;
      toggleCountryDropdown(false);
      toggleOriginDropdown(false);
      toggleCityDropdown(false);
      dd.classList.add('open');
      if (wrap) wrap.classList.add('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'true');
      const searchInput = document.getElementById('stateSearchInput');
      if (searchInput) {
        searchInput.value = '';
        renderStateOptions(state.currentStates);
        setTimeout(() => searchInput.focus(), 60);
      }
    } else {
      dd.classList.remove('open');
      if (wrap) wrap.classList.remove('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'false');
    }
  }

  function renderStateOptions(list, isSearching = false) {
    const container = document.getElementById('stateOptionsList');
    if (!container) return;

    if (!list || list.length === 0) {
      container.innerHTML = '<div class="p-4 text-center text-slate-400 text-xs">Không tìm thấy bang / tỉnh</div>';
      return;
    }

    let html = '';
    const iata = state.selectedCountry?.iata || '';
    const popularCodes = POPULAR_STATES_BY_COUNTRY[iata] || [];

    if (!isSearching && popularCodes.length > 0) {
      const popular = list.filter(s => popularCodes.includes(s.code));
      if (popular.length > 0) {
        html += '<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60 flex items-center gap-1"><i class="ph ph-star"></i> Bang / Tỉnh phổ biến</div>';
        html += popular.map(s => renderStateOption(s)).join('');
        html += `<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60">Tất cả bang / tỉnh (${list.length})</div>`;
      }
    }

    html += list.map(s => renderStateOption(s)).join('');
    container.innerHTML = html;
  }

  function renderStateOption(s) {
    const codeVal = s.code || s.name;
    const isSelected = state.selectedState === codeVal;
    return `
      <div class="state-option flex items-center justify-between px-3.5 py-2.5 cursor-pointer text-[13px] border-b border-slate-100 last:border-b-0 hover:bg-slate-50 transition-colors ${isSelected ? 'bg-slate-50 font-bold' : ''}"
           onclick="UPSQuote.selectState('${escapeHTML(codeVal)}', '${escapeHTML(s.name)}')">
        <div class="flex items-center gap-2.5">
          <span class="${isSelected ? 'font-bold text-navy-900' : 'text-slate-700'}">${escapeHTML(s.name)}</span>
          ${s.code ? `<span class="text-[10px] font-mono font-bold text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded tracking-wide">${escapeHTML(s.code)}</span>` : ''}
        </div>
        ${isSelected ? '<i class="ph-bold ph-check text-brand-red text-sm" aria-hidden="true"></i>' : ''}
      </div>
    `;
  }

  function selectState(code, name) {
    state.selectedState = code;
    const hidden = document.getElementById('destState');
    const display = document.getElementById('destStateDisplay');
    if (hidden) hidden.value = code;
    if (display) display.value = code ? `${name} (${code})` : name;
    toggleStateDropdown(false);
    onStateChange(code);
  }

  function filterStates(term) {
    const raw = (term || '').trim().toLowerCase();
    if (!raw) {
      renderStateOptions(state.currentStates);
      return;
    }
    const norm = removeVietnameseTones(raw);
    const filtered = (state.currentStates || []).filter(s => {
      const nameMatch = removeVietnameseTones(s.name).toLowerCase().includes(norm);
      const codeMatch = s.code && s.code.toLowerCase().includes(raw);
      return nameMatch || codeMatch;
    });
    renderStateOptions(filtered, true);
  }

  // ===== DESTINATION CITY COMBOBOX =====
  function toggleCityDropdown(isOpen) {
    const dd = document.getElementById('cityDropdown');
    const display = document.getElementById('destCityDisplay');
    const wrap = document.getElementById('cityCombobox');
    if (!dd) return;

    if (isOpen) {
      toggleCountryDropdown(false);
      toggleOriginDropdown(false);
      toggleStateDropdown(false);
      dd.classList.add('open');
      if (wrap) wrap.classList.add('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'true');
      const searchInput = document.getElementById('citySearchInput');
      if (searchInput) {
        searchInput.value = '';
        renderCityOptions(state.currentCities);
        setTimeout(() => searchInput.focus(), 60);
      }
    } else {
      dd.classList.remove('open');
      if (wrap) wrap.classList.remove('combobox-active');
      if (display) display.setAttribute('aria-expanded', 'false');
    }
  }

  function renderCityOptions(list, isSearching = false) {
    const container = document.getElementById('cityOptionsList');
    if (!container) return;

    let html = '';
    const stateKey = `${state.selectedCountry?.iata || ''}-${state.selectedState || ''}`;
    const popularCities = POPULAR_CITIES_BY_STATE[stateKey] || [];

    if (!list || list.length === 0) {
      html += '<div class="p-4 text-center text-slate-400 text-xs">Không có thành phố trong danh mục</div>';
    } else {
      if (!isSearching && popularCities.length > 0) {
        const popular = list.filter(c => popularCities.includes(c));
        if (popular.length > 0) {
          html += '<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60 flex items-center gap-1"><i class="ph ph-star"></i> Thành phố phổ biến</div>';
          html += popular.map(c => renderCityOption(c)).join('');
          html += `<div class="text-[10px] font-extrabold text-slate-400 uppercase tracking-wider px-3.5 py-1.5 bg-slate-50 border-b border-slate-200/60">Tất cả thành phố (${list.length})</div>`;
        }
      }

      const displayList = isSearching ? list.slice(0, 150) : list.slice(0, 200);
      html += displayList.map(c => renderCityOption(c)).join('');
      if (list.length > displayList.length) {
        html += `<div class="p-2 text-center text-slate-400 text-[11px] bg-slate-50 border-t border-slate-100">Hiển thị ${displayList.length} / ${list.length} kết quả. Hãy gõ từ khoá để tìm chính xác.</div>`;
      }
    }

    const isOtherSelected = state.selectedCity === 'other';
    html += `
      <div class="city-option flex items-center justify-between px-3.5 py-2.5 cursor-pointer text-[13px] border-t border-dashed border-slate-200 hover:bg-slate-50 text-slate-600 font-medium transition-colors ${isOtherSelected ? 'bg-slate-50 font-bold text-navy-900' : ''}"
           data-city="other" onclick="UPSQuote.selectCity('other')">
        <span class="italic text-slate-500 flex items-center gap-1.5">
          <i class="ph-bold ph-pencil-simple text-slate-400 text-xs" aria-hidden="true"></i>
          Khác (nhập địa chỉ cụ thể bên dưới)...
        </span>
        ${isOtherSelected ? '<i class="ph-bold ph-check text-brand-red text-sm" aria-hidden="true"></i>' : ''}
      </div>
    `;

    container.innerHTML = html;
  }

  function renderCityOption(city) {
    const isSelected = state.selectedCity === city;
    return `
      <div class="city-option flex items-center justify-between px-3.5 py-2.5 cursor-pointer text-[13px] border-b border-slate-100 last:border-b-0 hover:bg-slate-50 transition-colors ${isSelected ? 'bg-slate-50 font-bold' : ''}"
           data-city="${escapeHTML(city)}" onclick="UPSQuote.selectCity(this.getAttribute('data-city'))">
        <span class="${isSelected ? 'font-bold text-navy-900' : 'text-slate-700'}">${escapeHTML(city)}</span>
        ${isSelected ? '<i class="ph-bold ph-check text-brand-red text-sm" aria-hidden="true"></i>' : ''}
      </div>
    `;
  }

  function selectCity(city) {
    state.selectedCity = city;
    const hidden = document.getElementById('destCity');
    const display = document.getElementById('destCityDisplay');
    if (hidden) hidden.value = city;
    if (display) {
      display.value = (city === 'other') ? 'Khác (nhập địa chỉ cụ thể bên dưới)' : city;
    }
    toggleCityDropdown(false);
    onCityChange(city);
  }

  function filterCities(term) {
    const raw = (term || '').trim().toLowerCase();
    if (!raw) {
      renderCityOptions(state.currentCities);
      return;
    }
    const norm = removeVietnameseTones(raw);
    const filtered = (state.currentCities || []).filter(c => removeVietnameseTones(c).toLowerCase().includes(norm));
    renderCityOptions(filtered, true);
  }

  async function onStateChange(stateVal) {
    const cityDisplay = document.getElementById('destCityDisplay');
    const cityHidden = document.getElementById('destCity');

    const iata = state.selectedCountry ? state.selectedCountry.iata : 'US';
    const hasStates = state.currentStates && state.currentStates.length > 0;

    // If country requires a state and none is selected yet, lock city
    if (hasStates && !stateVal) {
      state.currentCities = [];
      state.selectedCity = '';
      if (cityHidden) cityHidden.value = '';
      if (cityDisplay) {
        cityDisplay.value = '';
        cityDisplay.placeholder = '— Chọn Bang / Tỉnh trước —';
        if (cityDisplay.parentElement && cityDisplay.parentElement.classList) {
          cityDisplay.parentElement.classList.add('opacity-60', 'pointer-events-none');
        }
      }
      renderCityOptions([]);
      return;
    }

    // State is selected or country has no state division: unlock city
    if (cityDisplay && cityDisplay.parentElement && cityDisplay.parentElement.classList) {
      cityDisplay.parentElement.classList.remove('opacity-60', 'pointer-events-none');
    }

    const fetchKey = stateVal ? `${iata}-${stateVal}` : `${iata}-_all`;
    currentCityFetchKey = fetchKey;

    let cityList = [];

    // Instant fallback to embedded top cities if available
    if (MAJOR_CITIES_BY_STATE[iata]) {
      if (stateVal && MAJOR_CITIES_BY_STATE[iata][stateVal]) {
        cityList = MAJOR_CITIES_BY_STATE[iata][stateVal];
      } else if (MAJOR_CITIES_BY_STATE[iata]._all) {
        cityList = MAJOR_CITIES_BY_STATE[iata]._all;
      }
    }

    if (stateVal) {
      if (cityChunkCache[fetchKey]) {
        cityList = cityChunkCache[fetchKey];
      } else {
        if (cityDisplay) {
          cityDisplay.placeholder = 'Đang tải danh sách thành phố...';
        }

        const baseUrl = state.config.citiesBaseUrl || '/wp-content/plugins/allship-ups-quote/public/assets/data/cities/';
        try {
          const res = await fetch(`${baseUrl}${fetchKey}.json`);
          if (res.ok) {
            const data = await res.json();
            if (Array.isArray(data) && data.length > 0) {
              cityChunkCache[fetchKey] = data;
              if (currentCityFetchKey === fetchKey) {
                cityList = data;
              }
            }
          }
        } catch (err) {
          // Keep embedded fallback list
        }
      }
    }

    if (currentCityFetchKey !== fetchKey) {
      return; // Discard stale request response
    }

    state.currentCities = cityList;

    if (cityDisplay && cityDisplay.parentElement && cityDisplay.parentElement.classList) {
      cityDisplay.parentElement.classList.remove('opacity-60', 'pointer-events-none');
    }

    if (cityHidden) {
      cityHidden.innerHTML = (cityList && cityList.length > 0)
        ? cityList.map(c => `<option value="${escapeHTML(c)}">${escapeHTML(c)}</option>`).join('')
        : '<option value="other">Khác</option>';
    }

    if (cityList && cityList.length > 0) {
      state.selectedCity = '';
      if (cityHidden) cityHidden.value = '';
      if (cityDisplay) {
        cityDisplay.value = '';
        cityDisplay.placeholder = '— Chọn Thành phố —';
      }
    } else {
      state.selectedCity = '';
      if (cityHidden) cityHidden.value = '';
      if (cityDisplay) {
        cityDisplay.value = '';
        cityDisplay.placeholder = 'Khác (nhập địa chỉ cụ thể bên dưới)';
      }
    }

    renderCityOptions(state.currentCities);
  }

  function onCityChange(cityVal) {
    const addrInput = document.getElementById('destAddress');
    if (cityVal === 'other' && addrInput) {
      addrInput.focus();
      addrInput.placeholder = 'Nhập tên thành phố, số nhà, tên đường...';
    }
  }

  // ===== G. PIECE MANAGER =====
  function renderPieces() {
    const container = document.getElementById('piecesContainer');
    if (!container) return;

    container.innerHTML = state.pieces.map((p, idx) => `
      <div class="piece-row" id="pieceRow_${p.id}">
        <input type="number" min="1" max="99" class="cell-input" value="${p.qty}"
               oninput="UPSQuote.updatePiece(${p.id}, 'qty', this.value)" placeholder="SL"
               aria-label="Số lượng kiện ${idx + 1}">
        <input type="number" step="0.1" min="0.1" class="cell-input" value="${p.weight}"
               oninput="UPSQuote.updatePiece(${p.id}, 'weight', this.value)" placeholder="kg"
               aria-label="Cân nặng thực tế kiện ${idx + 1}">
        <input type="number" step="1" min="0" class="cell-input" value="${p.len || ''}"
               oninput="UPSQuote.updatePiece(${p.id}, 'len', this.value)" placeholder="cm"
               aria-label="Chiều dài kiện ${idx + 1}">
        <input type="number" step="1" min="0" class="cell-input" value="${p.wid || ''}"
               oninput="UPSQuote.updatePiece(${p.id}, 'wid', this.value)" placeholder="cm"
               aria-label="Chiều rộng kiện ${idx + 1}">
        <input type="number" step="1" min="0" class="cell-input" value="${p.hei || ''}"
               oninput="UPSQuote.updatePiece(${p.id}, 'hei', this.value)" placeholder="cm"
               aria-label="Chiều cao kiện ${idx + 1}">
        <button type="button" class="btn-delete-piece" onclick="UPSQuote.removePiece(${p.id})" title="Xóa kiện" aria-label="Xóa kiện ${idx + 1}">
          <i class="ph-bold ph-x" aria-hidden="true"></i>
        </button>
      </div>
    `).join('');
  }

  function addNewPiece() {
    if (state.pieces.length >= 20) {
      showError('Tối đa 20 kiện hàng trong một đơn.');
      return;
    }
    const np = { id: state.pieceIdCounter++, qty: 1, weight: 1.0, len: 0, wid: 0, hei: 0 };
    state.pieces.push(np);
    renderPieces();
    recalculateMetrics();

    if (window.anime) {
      window.anime({
        targets: '#pieceRow_' + np.id,
        opacity: [0, 1],
        translateY: [12, 0],
        duration: 250,
        easing: 'easeOutQuad'
      });
    }
  }

  function removePiece(id) {
    if (state.pieces.length <= 1) {
      showError('Lô hàng cần có tối thiểu 1 kiện.');
      return;
    }
    state.pieces = state.pieces.filter(p => p.id !== id);
    renderPieces();
    recalculateMetrics();
  }

  function updatePiece(id, field, value) {
    const p = state.pieces.find(x => x.id === id);
    if (!p) return;
    p[field] = parseFloat(value) || 0;
    recalculateMetrics();
  }

  // ===== H. LIVE METRICS ENGINE =====
  function recalculateMetrics() {
    let totalPieces = 0;
    let totalActual = 0;
    let totalDim = 0;
    let totalChargeable = 0;
    const divisor = parseFloat(state.config.dim_divisor) || 5500;
    const step = parseFloat(state.config.rounding_step) || 0.5;

    state.pieces.forEach(p => {
      const qty = Math.max(1, p.qty || 1);
      totalPieces += qty;
      const actualPerPiece = p.weight || 0;
      const dimPerPiece = ((p.len || 0) * (p.wid || 0) * (p.hei || 0)) / divisor;
      totalActual += actualPerPiece * qty;
      totalDim += dimPerPiece * qty;
      const pieceChargeable = ceilToHalf(Math.max(actualPerPiece, dimPerPiece), step);
      totalChargeable += pieceChargeable * qty;
    });

    if (totalChargeable < step) totalChargeable = step;

    const elPieces = document.getElementById('totalPiecesVal');
    const elActual = document.getElementById('totalActualWeightVal');
    const elDim = document.getElementById('totalDimWeightVal');
    const elChargeable = document.getElementById('chargeableWeightVal');

    if (elPieces) elPieces.textContent = totalPieces;
    if (elActual) elActual.textContent = totalActual.toFixed(2) + ' kg';
    if (elDim) elDim.textContent = totalDim.toFixed(2) + ' kg';
    if (elChargeable) elChargeable.textContent = totalChargeable.toFixed(2) + ' kg';

    return { totalPieces, totalActual, totalDim, chargeable: totalChargeable };
  }

  // ===== I. QUOTE COMPARISON ENGINE =====
  function lookupRate(serviceCode, shipmentType, chargeableWeight, zoneVal) {
    const zoneKey = state.selectedCountry?.iata === 'US' ? 'US5' : String(zoneVal);
    if (!zoneVal || zoneVal <= 0) return { price: null, error: 'Tuyến không hỗ trợ dịch vụ này' };

    let rateTable;
    if (serviceCode === 'WXS') {
      if (shipmentType === 'document') {
        if (chargeableWeight > 5.0) return { price: null, error: 'Tài liệu chỉ hỗ trợ đến 5.0kg.' };
        rateTable = RATE_TABLES.doc_rates;
      } else {
        rateTable = RATE_TABLES.nondoc_rates;
      }
    } else if (serviceCode === 'XPD') {
      rateTable = RATE_TABLES.xpd_rates;
    } else if (serviceCode === 'WFM') {
      return lookupFreightRate(chargeableWeight, zoneKey);
    } else if (serviceCode === 'EXW') {
      const base = lookupRate('WXS', shipmentType, chargeableWeight, zoneVal);
      if (base && base.price) {
        return {
          ...base,
          price: Math.round(base.price * 1.25),
          ratePerKg: base.ratePerKg ? Math.round(base.ratePerKg * 1.25) : undefined,
          surchargeNote: 'Đã bao gồm phụ phí phát sớm (Early 8:30 AM)'
        };
      }
      return base;
    } else if (serviceCode === 'XPR') {
      const base = lookupRate('WXS', shipmentType, chargeableWeight, zoneVal);
      if (base && base.price) {
        return {
          ...base,
          price: Math.round(base.price * 1.15),
          ratePerKg: base.ratePerKg ? Math.round(base.ratePerKg * 1.15) : undefined,
          surchargeNote: 'Đã bao gồm phụ phí phát ưu tiên (Plus 10:30 AM)'
        };
      }
      return base;
    } else if (serviceCode === 'WXP') {
      const base = lookupFreightRate(chargeableWeight, zoneKey);
      if (base && base.price) {
        return {
          ...base,
          price: Math.round(base.price * 1.22),
          ratePerKg: base.ratePerKg ? Math.round(base.ratePerKg * 1.22) : undefined,
          minimum: base.minimum ? Math.round(base.minimum * 1.22) : undefined,
          warning: chargeableWeight < 71 ? 'Hỏa tốc hàng nặng. Dưới 71kg tính cước tối thiểu (Minimum).' : null
        };
      }
      return base;
    } else {
      return { price: null, error: 'Dịch vụ không khả dụng' };
    }

    const fixedRows = rateTable.filter(r => !r.weight_label.includes('-') && !r.weight_label.startsWith('>') && r.weight_label !== 'UPS Envelope');

    if (chargeableWeight <= 20) {
      let matched = fixedRows.find(r => parseFloat(r.weight_label) === chargeableWeight);
      if (!matched) {
        const candidates = fixedRows.filter(r => parseFloat(r.weight_label) >= chargeableWeight);
        candidates.sort((a, b) => parseFloat(a.weight_label) - parseFloat(b.weight_label));
        matched = candidates[0];
      }
      if (matched && matched.rates[zoneKey] !== undefined) {
        return { price: matched.rates[zoneKey], unit: 'flat', matchedWeight: parseFloat(matched.weight_label) };
      }
    }

    for (let r of rateTable) {
      const lbl = r.weight_label;
      if (lbl.includes('-')) {
        const parts = lbl.split('-').map(Number);
        if (chargeableWeight >= parts[0] && chargeableWeight <= parts[1]) {
          const perKg = r.rates[zoneKey];
          return { price: perKg * chargeableWeight, unit: 'per_kg', ratePerKg: perKg, bracket: lbl };
        }
      } else if (lbl.startsWith('>') && chargeableWeight >= 1000) {
        const perKg = r.rates[zoneKey];
        return { price: perKg * chargeableWeight, unit: 'per_kg', ratePerKg: perKg, bracket: lbl };
      }
    }

    if (serviceCode === 'WXS' && shipmentType === 'document' && chargeableWeight <= 0.5) {
      const envRow = rateTable.find(r => r.weight_label === 'UPS Envelope');
      if (envRow) return { price: envRow.rates[zoneKey], unit: 'flat', matchedWeight: 0.5 };
    }

    return { price: null, error: 'Không tìm thấy mức giá phù hợp' };
  }

  function lookupFreightRate(chargeableWeight, zoneKey) {
    const minRow = RATE_TABLES.wfm_rates.find(r => r.weight_label === 'Minimum');
    const minPrice = minRow ? minRow.rates[zoneKey] : 0;

    for (let r of RATE_TABLES.wfm_rates) {
      const lbl = r.weight_label;
      if (lbl.includes('-')) {
        const [lo, hi] = lbl.split('-').map(Number);
        if (chargeableWeight >= lo && chargeableWeight <= hi) {
          const calc = r.rates[zoneKey] * chargeableWeight;
          return { price: Math.max(minPrice, calc), unit: 'per_kg', ratePerKg: r.rates[zoneKey], bracket: lbl, minimum: minPrice };
        }
      } else if (lbl.startsWith('>') && chargeableWeight >= 1000) {
        const calc = r.rates[zoneKey] * chargeableWeight;
        return { price: Math.max(minPrice, calc), unit: 'per_kg', ratePerKg: r.rates[zoneKey], bracket: lbl, minimum: minPrice };
      }
    }

    return {
      price: minPrice,
      unit: 'minimum',
      warning: chargeableWeight < 71 ? 'Freight thường áp dụng từ 71kg. Dưới 71kg tính giá tối thiểu (Minimum).' : null
    };
  }

  function showCalculationLoading() {
    const overlay = document.getElementById('quoteCalculationOverlay');
    if (overlay) {
      overlay.classList.add('is-active');
    }
    if (document && document.body && document.body.style) {
      document.body.style.overflow = 'hidden';
    }
  }

  function hideCalculationLoading() {
    const overlay = document.getElementById('quoteCalculationOverlay');
    if (overlay) {
      overlay.classList.remove('is-active');
    }
    if (document && document.body && document.body.style) {
      document.body.style.overflow = '';
    }
  }

  function performCalculation() {
    hideError();
    if (!state.selectedCountry) {
      showError('Vui lòng chọn quốc gia đến.');
      return;
    }

    const hasWeight = state.pieces.some(p => (p.weight || 0) > 0);
    if (!hasWeight) {
      showError('Vui lòng nhập cân nặng cho ít nhất 1 kiện hàng.');
      return;
    }

    const btn = document.getElementById('btnCalculate');
    if (btn) {
      btn.classList.add('loading');
      btn.disabled = true;
    }

    showCalculationLoading();
    const startTime = Date.now();
    const MIN_CALC_DELAY = 1500; // 1.5s delay conveys dedicated route optimization calculation

    const finishCalculation = (callback) => {
      const elapsed = Date.now() - startTime;
      const remaining = Math.max(0, MIN_CALC_DELAY - elapsed);
      setTimeout(() => {
        hideCalculationLoading();
        if (btn) {
          btn.classList.remove('loading');
          btn.disabled = false;
        }
        if (typeof callback === 'function') callback();
      }, remaining);
    };

    // Call REST API asynchronously with service_code: ALL (Thin Client + Server-side Cached Batch)
    if (state.config.apiBase) {
      const payload = {
        direction: state.direction,
        destination_iata: state.selectedCountry.iata,
        service_code: 'ALL',
        shipment_type: state.shipmentType,
        origin_province: state.originProvince || document.getElementById('originProvinceDisplay')?.value || document.getElementById('originProvince')?.value || 'TP. Hồ Chí Minh',
        destination_state: document.getElementById('destState')?.value || '',
        destination_city: document.getElementById('destCity')?.value || '',
        destination_postal_code: document.getElementById('destZipcode')?.value || '',
        destination_address: document.getElementById('destAddress')?.value || '',
        pieces: state.pieces.map(p => ({
          quantity: Math.max(1, p.qty || 1),
          actual_weight_kg: p.weight || 0,
          length_cm: p.len || 0,
          width_cm: p.wid || 0,
          height_cm: p.hei || 0
        }))
      };

      fetch(state.config.apiBase + '/calculate', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': state.config.nonce || ''
        },
        body: JSON.stringify(payload)
      })
      .then(res => res.json())
      .then(res => {
        finishCalculation(() => {
          if (res && res.success && res.data && Array.isArray(res.data.services)) {
            applyServerCalculatedResults(res.data);
          } else {
            executeCalculation();
          }
        });
      })
      .catch(err => {
        console.warn('UPS REST calculate sync warning:', err);
        finishCalculation(() => {
          executeCalculation();
        });
      });
      return;
    }

    finishCalculation(() => {
      executeCalculation();
    });
  }

  function applyServerCalculatedResults(data) {
    if (data && data.quote_log_id) {
      state.lastQuoteLogId = data.quote_log_id;
    }
    const metrics = recalculateMetrics();
    const weight = data.metrics?.chargeable_weight_kg || metrics.chargeable;
    const totalPiecesCount = state.pieces.reduce((sum, p) => sum + Math.max(1, p.qty || 1), 0);
    const c = state.selectedCountry;
    const isExport = state.direction === 'export';

    const provSelect = document.getElementById('originProvince');
    const originLabel = state.originProvince || document.getElementById('originProvinceDisplay')?.value || provSelect?.value || 'TP. Hồ Chí Minh';

    const stateSelect = document.getElementById('destState');
    const selectedStateText = stateSelect && stateSelect.value && !stateSelect.disabled && stateSelect.options && stateSelect.selectedIndex >= 0
      ? (stateSelect.options[stateSelect.selectedIndex]?.getAttribute('data-name') || stateSelect.value)
      : (stateSelect?.value || '');
    const citySelect = document.getElementById('destCity');
    const selectedCity = citySelect && citySelect.value && citySelect.value !== 'other' ? citySelect.value : '';
    const destZip = document.getElementById('destZipcode')?.value.trim() || '';

    let destDetails = [];
    if (selectedCity) destDetails.push(selectedCity);
    if (selectedStateText) destDetails.push(selectedStateText);
    if (destZip) destDetails.push('(' + destZip + ')');

    const destDetailStr = destDetails.length > 0 ? destDetails.join(', ') + ' - ' : '';
    const countryStr = destDetailStr ? `${destDetailStr}${c.name} (${c.iata})` : `${c.name} (${c.iata})`;

    // Update Ribbon Card
    const routeTitle = isExport
      ? `${originLabel} ➔ ${c.name} (${c.iata})`
      : `${c.name} (${c.iata}) ➔ ${originLabel}`;

    const dirLabelEl = document.getElementById('ribbonDirLabel');
    if (dirLabelEl) {
      dirLabelEl.textContent = isExport ? 'Xuất khẩu' : 'Nhập khẩu';
      dirLabelEl.className = isExport ? 'text-blue-700 font-extrabold' : 'text-emerald-700 font-extrabold';
    }

    const servLabelEl = document.getElementById('ribbonServiceLabel');
    if (servLabelEl) servLabelEl.textContent = 'UPS ' + state.service;

    const routeTitleEl = document.getElementById('ribbonRouteTitle');
    if (routeTitleEl) routeTitleEl.textContent = routeTitle;

    const weightDisplayEl = document.getElementById('ribbonWeightDisplay');
    if (weightDisplayEl) {
      weightDisplayEl.innerHTML = `<span>${weight.toFixed(1)} kg</span> <span class="text-[10px] font-bold opacity-80">(${totalPiecesCount} kiện)</span>`;
    }

    const destShortEl = document.getElementById('ribbonDestShort');
    if (destShortEl) {
      const shortLoc = isExport
        ? (selectedCity ? `${selectedCity}, ${c.iata}` : `${c.name} (${c.iata})`)
        : originLabel;
      destShortEl.textContent = shortLoc;
      destShortEl.title = isExport ? countryStr : originLabel;
    }

    const zoneShortEl = document.getElementById('ribbonZoneShort');
    if (zoneShortEl) {
      zoneShortEl.textContent = 'Đã chuẩn hóa';
    }

    const typeShortEl = document.getElementById('ribbonTypeShort');
    if (typeShortEl) {
      const activeConf = SERVICE_REGISTRY[state.service];
      typeShortEl.textContent = activeConf?.has_document_split
        ? (state.shipmentType === 'document' ? 'Tài liệu' : 'Hàng hóa')
        : (state.service === 'WFM' || state.service === 'WXP' ? 'Freight' : 'Hàng hóa');
    }

    const services = data.services.map(s => {
      const reg = SERVICE_REGISTRY[s.code] || {};
      return {
        code: s.code,
        name: s.name || reg.name || s.code,
        transit: s.transit || reg.desc_vi || '1-3 ngày',
        icon: reg.icon || 'ph-airplane-tilt',
        iconBg: reg.iconBg || '#FEF3C7',
        iconColor: reg.iconColor || '#D97706',
        zone: s.zone || null,
        calc: s.error
          ? { price: null, error: s.error }
          : { 
              price: s.price, total: s.total_price, vat: s.vat, notes: s.notes, unit: 'server',
              zone: s.zone, rate_zone: s.rate_zone, rate_card_id: s.rate_card_id,
              actual_weight_kg: s.actual_weight_kg, dim_weight_kg: s.dim_weight_kg, chargeable_weight_kg: s.chargeable_weight_kg
            }
      };
    });

    state.calculatedResults = services;
    renderCalculatedServicesView(services, data.best_price, weight);
  }

  function executeCalculation() {
    const metrics = recalculateMetrics();
    const weight = metrics.chargeable;
    const totalPiecesCount = state.pieces.reduce((sum, p) => sum + Math.max(1, p.qty || 1), 0);
    const c = state.selectedCountry;
    const isExport = state.direction === 'export';

    const provSelect = document.getElementById('originProvince');
    const originLabel = state.originProvince || document.getElementById('originProvinceDisplay')?.value || provSelect?.value || 'TP. Hồ Chí Minh';

    const stateSelect = document.getElementById('destState');
    const selectedStateText = stateSelect && stateSelect.value && !stateSelect.disabled && stateSelect.options && stateSelect.selectedIndex >= 0
      ? (stateSelect.options[stateSelect.selectedIndex]?.getAttribute('data-name') || stateSelect.value)
      : (stateSelect?.value || '');
    const citySelect = document.getElementById('destCity');
    const selectedCity = citySelect && citySelect.value && citySelect.value !== 'other' ? citySelect.value : '';
    const destZip = document.getElementById('destZipcode')?.value.trim() || '';

    let destDetails = [];
    if (selectedCity) destDetails.push(selectedCity);
    if (selectedStateText) destDetails.push(selectedStateText);
    if (destZip) destDetails.push('(' + destZip + ')');

    const destDetailStr = destDetails.length > 0 ? destDetails.join(', ') + ' - ' : '';
    const countryStr = destDetailStr ? `${destDetailStr}${c.name} (${c.iata})` : `${c.name} (${c.iata})`;

    // Update Ribbon Card
    const routeTitle = isExport
      ? `${originLabel} ➔ ${c.name} (${c.iata})`
      : `${c.name} (${c.iata}) ➔ ${originLabel}`;

    const dirLabelEl = document.getElementById('ribbonDirLabel');
    if (dirLabelEl) {
      dirLabelEl.textContent = isExport ? 'Xuất khẩu' : 'Nhập khẩu';
      dirLabelEl.className = isExport ? 'text-blue-700 font-extrabold' : 'text-emerald-700 font-extrabold';
    }

    const servLabelEl = document.getElementById('ribbonServiceLabel');
    if (servLabelEl) servLabelEl.textContent = 'UPS ' + state.service;

    const routeTitleEl = document.getElementById('ribbonRouteTitle');
    if (routeTitleEl) routeTitleEl.textContent = routeTitle;

    const weightDisplayEl = document.getElementById('ribbonWeightDisplay');
    if (weightDisplayEl) {
      weightDisplayEl.innerHTML = `<span>${weight.toFixed(1)} kg</span> <span class="text-[10px] font-bold opacity-80">(${totalPiecesCount} kiện)</span>`;
    }

    const destShortEl = document.getElementById('ribbonDestShort');
    if (destShortEl) {
      const shortLoc = isExport
        ? (selectedCity ? `${selectedCity}, ${c.iata}` : `${c.name} (${c.iata})`)
        : originLabel;
      destShortEl.textContent = shortLoc;
      destShortEl.title = isExport ? countryStr : originLabel;
    }

    const zoneShortEl = document.getElementById('ribbonZoneShort');
    if (zoneShortEl) {
      zoneShortEl.textContent = c.iata === 'US' ? 'US5 (Zone 5)' : ('Zone ' + getZoneForService(c));
    }

    const typeShortEl = document.getElementById('ribbonTypeShort');
    if (typeShortEl) {
      const activeConf = SERVICE_REGISTRY[state.service];
      typeShortEl.textContent = activeConf?.has_document_split
        ? (state.shipmentType === 'document' ? 'Tài liệu' : 'Hàng hóa')
        : (state.service === 'WFM' || state.service === 'WXP' ? 'Freight' : 'Hàng hóa');
    }

    // 6 Services calculations (Offline / Test Fallback)
    const services = [
      {
        code: 'EXW', name: 'Express Early', transit: '1-2 ngày (Giao sớm)',
        icon: 'ph-globe', iconBg: '#FEF3C7', iconColor: '#D97706',
        zone: c.wxs,
        calc: c.wxs > 0 ? lookupRate('EXW', state.shipmentType, weight, c.wxs) : { price: null, error: 'Không hỗ trợ' }
      },
      {
        code: 'XPR', name: 'Express Plus', transit: '1-2 ngày (Giao ưu tiên)',
        icon: 'ph-rocket-launch', iconBg: '#EDE9FE', iconColor: '#7C3AED',
        zone: c.wxs,
        calc: c.wxs > 0 ? lookupRate('XPR', state.shipmentType, weight, c.wxs) : { price: null, error: 'Không hỗ trợ' }
      },
      {
        code: 'WXS', name: 'Express Saver', transit: '1-3 ngày',
        icon: 'ph-airplane-tilt', iconBg: '#FEF3C7', iconColor: '#D97706',
        zone: c.wxs,
        calc: c.wxs > 0 ? lookupRate('WXS', state.shipmentType, weight, c.wxs) : { price: null, error: 'Không hỗ trợ' }
      },
      {
        code: 'XPD', name: 'Expedited', transit: '3-5 ngày',
        icon: 'ph-truck', iconBg: '#E0E7FF', iconColor: '#4338CA',
        zone: c.xpd,
        calc: c.xpd > 0 ? lookupRate('XPD', 'nondocument', weight, c.xpd) : { price: null, error: 'Không hỗ trợ' }
      },
      {
        code: 'WXP', name: 'Express Freight', transit: '1-2 ngày (Trên 70kg)',
        icon: 'ph-lightning', iconBg: '#FEE2E2', iconColor: '#DC2626',
        zone: c.wfm,
        calc: c.wfm > 0 ? lookupRate('WXP', 'nondocument', weight, c.wfm) : { price: null, error: 'Không hỗ trợ' }
      },
      {
        code: 'WFM', name: 'Freight Midday', transit: '3-5 ngày (Trên 70kg)',
        icon: 'ph-crane', iconBg: '#ECFDF5', iconColor: '#059669',
        zone: c.wfm,
        calc: c.wfm > 0 ? lookupRate('WFM', 'nondocument', weight, c.wfm) : { price: null, error: 'Không hỗ trợ' }
      }
    ];

    state.calculatedResults = services;

    const availablePrices = services.filter(s => s.calc.price > 0).map(s => s.calc.price);
    const bestPrice = availablePrices.length > 0 ? Math.min(...availablePrices) : null;

    renderCalculatedServicesView(services, bestPrice, weight);
  }

  function renderCalculatedServicesView(services, bestPrice, weight) {
    const totalPiecesCount = state.pieces.reduce((sum, p) => sum + Math.max(1, p.qty || 1), 0);
    const availablePrices = services.filter(s => s.calc && s.calc.price !== null && s.calc.price > 0).map(s => s.calc.price);
    const c = state.selectedCountry || { iata: '', name: '' };
    const isExport = state.direction === 'export';

    // Rule: Hide unquoted/unavailable services from results
    const availableServices = services.filter(s => s.calc && s.calc.price !== null && s.calc.price > 0);

    const displayServices = (state.categoryFilter === 'all')
      ? availableServices
      : availableServices.filter(s => SERVICE_REGISTRY[s.code]?.cat === state.categoryFilter);

    // Auto-select first available service if currently selected service has no rate
    if (displayServices.length > 0 && !displayServices.some(s => s.code === state.service)) {
      state.service = displayServices[0].code;
      const servLabelEl = document.getElementById('ribbonServiceLabel');
      if (servLabelEl) servLabelEl.textContent = 'UPS ' + state.service;
    }

    // 1. Mobile Compact Comparison List
    const mobileList = document.getElementById('mobileCompactListContainer');
    if (mobileList) {
      if (displayServices.length === 0) {
        mobileList.innerHTML = `
          <div class="p-4 text-center text-xs text-slate-500 bg-slate-50 rounded-xl">
            Không có bảng giá nào khả dụng cho phân loại này.
          </div>
        `;
      } else {
        mobileList.innerHTML = displayServices.map(s => {
          const isBest = s.calc.price === bestPrice && availablePrices.length > 1;
          const isCurrent = s.code === state.service;

          let badgeTag = '';
          if (isBest) {
            badgeTag = '<span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800">Giá tốt nhất</span>';
          } else if (s.code === 'EXW') {
            badgeTag = '<span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-amber-100 text-amber-800">Sớm</span>';
          } else if (s.code === 'XPR') {
            badgeTag = '<span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-purple-100 text-purple-800">Ưu tiên</span>';
          } else if (s.code === 'WXS') {
            badgeTag = '<span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">Phổ biến</span>';
          } else if (s.code === 'WXP') {
            badgeTag = '<span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-rose-100 text-rose-800">Hỏa tốc nặng</span>';
          } else if (s.code === 'WFM') {
            badgeTag = '<span class="text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">Tiết kiệm nặng</span>';
          }

          return `
            <div class="mobile-comp-row ${isCurrent ? 'active' : ''} rounded-xl p-3 cursor-pointer" onclick="UPSQuote.selectServiceFromMobileList('${s.code}')">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                  <div class="w-4 h-4 rounded-full border-2 border-slate-300 comp-radio-dot flex items-center justify-center shrink-0">
                    ${isCurrent ? '<div class="w-1.5 h-1.5 rounded-full bg-white"></div>' : ''}
                  </div>
                  <div class="w-9 h-9 rounded-lg flex items-center justify-center text-lg shrink-0" style="background:${s.iconBg}; color:${s.iconColor};">
                    <i class="ph-bold ${s.icon}"></i>
                  </div>
                  <div>
                    <div class="flex items-center gap-1.5">
                      <span class="text-xs font-black text-navy-900">${s.name}</span>
                      <span class="text-[9px] font-mono font-bold text-slate-400">${s.code}</span>
                    </div>
                    <div class="flex items-center gap-1.5 mt-0.5">
                      <span class="text-[10px] text-slate-500 font-medium">${s.transit}</span>
                      ${badgeTag}
                    </div>
                  </div>
                </div>
                <div class="text-right flex items-center gap-2">
                  <div>
                    <div class="text-sm font-black ${isCurrent ? 'text-brand-red' : 'text-navy-900'}">${formatVND(s.calc.price)} đ</div>
                    <div class="text-[9px] text-slate-400">Tạm tính</div>
                  </div>
                  <button type="button" onclick="event.stopPropagation(); UPSQuote.openPdfExportModal('${s.code}')" title="Xuất báo giá PDF" class="w-8 h-8 rounded-lg bg-slate-100 hover:bg-slate-200 text-brand-red flex items-center justify-center cursor-pointer active:scale-95 border border-slate-200">
                    <i class="ph-bold ph-file-pdf text-sm"></i>
                  </button>
                </div>
              </div>
            </div>
          `;
        }).join('');
      }
    }

    // 2. Desktop Cards Grid
    const grid = document.getElementById('serviceCardsGrid');
    if (grid) {
      if (displayServices.length === 0) {
        grid.className = 'grid grid-cols-1 max-w-xl mx-auto mb-4 md:mb-7';
        grid.innerHTML = `
          <div class="col-span-full py-10 px-6 text-center bg-white rounded-2xl border border-slate-200 shadow-sm max-w-xl mx-auto">
            <div class="w-12 h-12 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center text-2xl mx-auto mb-3">
              <i class="ph-bold ph-info"></i>
            </div>
            <div class="text-base font-extrabold text-navy-900 mb-1">Chưa có bảng giá cho dịch vụ này</div>
            <div class="text-xs text-slate-500 mb-4">Vui lòng chọn dịch vụ khác hoặc liên hệ hotline Allship để nhận báo giá ưu đãi riêng.</div>
            <button type="button" onclick="UPSQuote.openBookingModal()" class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-red text-white text-xs font-bold rounded-xl shadow-brand hover:bg-brand-red-hover transition-all cursor-pointer">
              <i class="ph-bold ph-phone-call"></i> Liên hệ hotline 1900 252 338
            </button>
          </div>
        `;
      } else {
        if (displayServices.length === 1) {
          grid.className = 'grid grid-cols-1 max-w-md mx-auto gap-4 md:gap-5 mb-4 md:mb-7';
        } else if (displayServices.length === 2) {
          grid.className = 'grid grid-cols-1 md:grid-cols-2 max-w-3xl mx-auto gap-4 md:gap-5 mb-4 md:mb-7';
        } else {
          grid.className = 'grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-5 mb-4 md:mb-7';
        }

        grid.innerHTML = displayServices.map(s => {
          const isBest = s.calc.price === bestPrice && availablePrices.length > 1;
          const isActive = s.code === state.service;

          let priceHTML = `<div class="text-2xl font-black tracking-tight flex items-baseline gap-1 ${isActive ? 'text-brand-red' : 'text-navy-900'}">${formatVND(s.calc.price)} <span class="text-sm font-bold text-slate-500">đ</span></div>`;
          if (s.calc.unit === 'per_kg') {
            priceHTML += `<div class="text-[11px] text-slate-500 mt-1 font-medium">= ${formatVND(s.calc.ratePerKg)} đ/kg × ${weight.toFixed(1)}kg (bậc ${s.calc.bracket})</div>`;
          }
          if (s.calc.warning) {
            priceHTML += `<div class="text-[11px] text-amber-600 mt-1 font-medium"><i class="ph ph-warning"></i> ${s.calc.warning}</div>`;
          }
          const detailHTML = `
            <button type="button" onclick="UPSQuote.openPiecesDetailModal()"
                    class="w-full py-2 text-xs font-bold text-blue-600 hover:text-blue-800 bg-blue-50/60 hover:bg-blue-100 rounded-xl transition-all flex items-center justify-center gap-1.5 cursor-pointer mb-3 border border-blue-100">
              <i class="ph-bold ph-list-numbers text-sm" aria-hidden="true"></i>
              <span>Xem bảng kê ${totalPiecesCount} kiện</span>
            </button>
          `;

          let tagHTML = '';
          if (isBest) {
            tagHTML = '<span class="absolute top-3 right-3 bg-brand-red text-white text-[10px] font-extrabold uppercase px-2 py-0.5 rounded tracking-wide shadow-xs">GIÁ TỐT NHẤT</span>';
          } else if (s.code === 'EXW') {
            tagHTML = '<span class="absolute top-3 right-3 bg-amber-100 text-amber-800 text-[10px] font-extrabold uppercase px-2 py-0.5 rounded tracking-wide">SỚM</span>';
          } else if (s.code === 'XPR') {
            tagHTML = '<span class="absolute top-3 right-3 bg-purple-100 text-purple-800 text-[10px] font-extrabold uppercase px-2 py-0.5 rounded tracking-wide">ƯU TIÊN</span>';
          } else if (s.code === 'WXS') {
            tagHTML = '<span class="absolute top-3 right-3 bg-amber-50 text-amber-800 text-[10px] font-extrabold uppercase px-2 py-0.5 rounded tracking-wide">PHỔ BIẾN</span>';
          } else if (s.code === 'WXP') {
            tagHTML = '<span class="absolute top-3 right-3 bg-rose-100 text-rose-800 text-[10px] font-extrabold uppercase px-2 py-0.5 rounded tracking-wide">HỎA TỐC NẶNG</span>';
          } else if (s.code === 'WFM') {
            tagHTML = '<span class="absolute top-3 right-3 bg-emerald-50 text-emerald-800 text-[10px] font-extrabold uppercase px-2 py-0.5 rounded tracking-wide">TIẾT KIỆM NẶNG</span>';
          }

          return `
            <div class="service-card bg-white rounded-2xl border-[1.5px] p-5 md:p-6 flex flex-col justify-between transition-all duration-300 relative overflow-hidden shadow-sm hover:shadow-xl hover:-translate-y-1 ${isBest ? 'border-brand-red bg-linear-to-b from-brand-red/[0.02] to-white shadow-[0_8px_24px_rgba(206,32,39,0.1)]' : 'border-slate-200'}">
              ${tagHTML}
              <div>
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-11 h-11 rounded-xl flex items-center justify-center text-xl shrink-0" style="background:${s.iconBg}; color:${s.iconColor};">
                    <i class="ph-bold ${s.icon}" aria-hidden="true"></i>
                  </div>
                  <div>
                    <div class="text-base font-extrabold text-navy-900">${s.name}</div>
                    <div class="text-xs text-slate-500 font-medium mt-0.5">UPS ${s.code} · ${s.transit}</div>
                  </div>
                </div>
                <div class="space-y-1.5 py-3 border-y border-slate-100 mb-4">
                  <div class="flex justify-between items-center text-xs">
                    <span class="text-slate-500 font-medium">Zone</span>
                    <span class="font-bold text-navy-900">${c.iata === 'US' ? 'US5 (Zone 5)' : (s.zone > 0 ? 'Zone ' + s.zone : '—')}</span>
                  </div>
                  <div class="flex justify-between items-center text-xs">
                    <span class="text-slate-500 font-medium">Cân tính cước</span>
                    <span class="font-bold text-navy-900">${weight.toFixed(1)} kg</span>
                  </div>
                  <div class="flex justify-between items-center text-xs">
                    <span class="text-slate-500 font-medium">${isExport ? 'Nước đến' : 'Nước gửi'}</span>
                    <span class="font-bold text-navy-900">${c.name}</span>
                  </div>
                </div>
              </div>
              <div>
                <div class="bg-slate-50 rounded-xl p-3 mb-3">
                  <div class="text-[11px] font-bold text-slate-500 uppercase tracking-wide mb-1">Cước tạm tính:</div>
                  ${priceHTML}
                </div>
                ${detailHTML}
                <div class="grid grid-cols-2 gap-2.5 mt-3 pt-2.5 border-t border-slate-100">
                  <button type="button" class="h-10 rounded-xl font-sans text-xs font-bold flex items-center justify-center gap-1.5 transition-all duration-200 cursor-pointer bg-white border border-slate-200 text-slate-700 hover:border-brand-red/40 hover:text-brand-red hover:bg-red-50/40 shadow-xs active:scale-98" onclick="UPSQuote.openPdfExportModal('${s.code}')" title="Xuất file Báo Giá PDF chính thức">
                    <i class="ph-bold ph-file-pdf text-brand-red text-base" aria-hidden="true"></i> Tải PDF
                  </button>
                  <button type="button" class="h-10 rounded-xl font-sans text-xs font-bold flex items-center justify-center gap-1.5 transition-all duration-200 cursor-pointer ${isActive ? 'bg-brand-red text-white shadow-brand hover:bg-brand-red-hover' : 'bg-navy-900 text-white hover:bg-navy-800 shadow-xs'} active:scale-98" onclick="UPSQuote.openBookingModal('${s.code}')" title="Liên hệ chuyên viên tư vấn chi tiết">
                    <i class="ph-bold ph-chat-centered-dots text-sm" aria-hidden="true"></i> Liên hệ tư vấn
                  </button>
                </div>
              </div>
            </div>
          `;
        }).join('');
      }
    }

    // Display result section
    const resultSec = document.getElementById('resultSection');
    if (resultSec) {
      resultSec.style.display = 'block';
      if (window.anime) {
        window.anime({
          targets: resultSec,
          opacity: [0, 1],
          translateY: [20, 0],
          duration: 350,
          easing: 'easeOutCubic'
        });
      } else {
        resultSec.style.opacity = '1';
      }
      resultSec.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    updateRealtimeNoticeDate();
    updateStickyBar();
  }

  // ===== J. BOOKING MODAL & LEAD SUBMIT =====
  function getStandardServiceName(serviceCode, direction, shipmentType) {
    const isExport = direction !== 'import';
    const isDoc = shipmentType === 'document';
    const suffix = isExport ? 'Export' : 'Import';

    switch (serviceCode) {
      case 'EXW':
        return isDoc
          ? `Express Early Document (EXW Doc) - ${suffix}`
          : `Express Early Non-Document (EXW Non-Doc) - ${suffix}`;
      case 'XPR':
        return isDoc
          ? `Express Plus Document (XPR Doc) - ${suffix}`
          : `Express Plus Non-Document (XPR Non-Doc) - ${suffix}`;
      case 'WXS':
        return isDoc
          ? `Express Saver Document (WXS Doc) - ${suffix}`
          : `Express Saver Non-Document (WXS Non-Doc) - ${suffix}`;
      case 'XPD':
        return `Expedited (XPD) - ${suffix}`;
      case 'WXP':
        return `Express Freight (WXP) - ${suffix}`;
      case 'WFM':
        return `Freight Midday (WFM) - ${suffix}`;
      default:
        return `${serviceCode} - ${suffix}`;
    }
  }

  function buildBookingDetailedMessage(customNotes, isExport, fromText, toText, standardServiceName, weightText, priceText, pieces) {
    let msg = '';
    const cleanNotes = (customNotes || '').trim();
    if (cleanNotes) {
      msg += `[Ghi chú khách hàng]:\n${cleanNotes}\n\n`;
    }

    msg += `--------------------------------------\n`;
    msg += `THÔNG TIN BÁO GIÁ UPS:\n`;
    msg += `• Tuyến: ${isExport ? 'Xuất khẩu' : 'Nhập khẩu'} (${fromText} ➔ ${toText})\n`;
    msg += `• Dịch vụ: ${standardServiceName}\n`;
    msg += `• Trọng lượng tính cước: ${weightText}\n`;
    msg += `• Tạm tính: ${priceText}\n`;

    const pieceList = Array.isArray(pieces) ? pieces : [];
    if (pieceList.length > 0) {
      msg += `• Chi tiết các kiện hàng (${pieceList.length} kiện):\n`;
      pieceList.forEach((p, idx) => {
        const qty = Math.max(1, p.qty || 1);
        const weight = Number(p.weight) || 0;
        const l = Number(p.len) || 0;
        const w = Number(p.wid) || 0;
        const h = Number(p.hgt) || Number(p.hei) || 0;
        const divisor = state.config.dim_divisor || 5500;
        const dimWeight = (l * w * h) / divisor;
        msg += `  - Kiện #${idx + 1}: ${qty} kiện/thùng, ${weight} kg/kiện, KT: ${l} × ${w} × ${h} cm (TLTT: ${dimWeight.toFixed(2)} kg)\n`;
      });
    }

    return msg.trim();
  }

  function resetBookingModalState() {
    const formState = document.getElementById('bookingModalFormState');
    const successState = document.getElementById('bookingModalSuccessState');
    const noticeEl = document.getElementById('bookingNotice');
    const submitBtn = document.getElementById('btnSubmitBooking');

    if (formState) formState.classList.remove('hidden');
    if (successState) successState.classList.add('hidden');
    if (noticeEl) {
      noticeEl.className = 'hidden mb-3 p-3 rounded-xl text-xs font-semibold';
      noticeEl.innerHTML = '';
    }
    if (submitBtn) {
      submitBtn.classList.remove('loading');
      submitBtn.disabled = false;
    }
  }

  function openBookingModal(targetServiceCode = null) {
    resetBookingModalState();
    const serviceCodeToUse = targetServiceCode || state.service;
    const isExport = state.direction === 'export';
    const provSelect = document.getElementById('originProvince');
    const originLabel = state.originProvince || document.getElementById('originProvinceDisplay')?.value || provSelect?.value || 'TP. Hồ Chí Minh';
    const c = state.selectedCountry || { name: 'United States', iata: 'US' };

    const stateSelect = document.getElementById('destState');
    const stateVal = stateSelect && stateSelect.value && !stateSelect.disabled && stateSelect.options && stateSelect.selectedIndex >= 0
      ? (stateSelect.options[stateSelect.selectedIndex]?.getAttribute('data-name') || stateSelect.value)
      : (stateSelect?.value || '');
    const citySelect = document.getElementById('destCity');
    const cityVal = citySelect && citySelect.value && citySelect.value !== 'other' ? citySelect.value : '';
    const zipVal = document.getElementById('destZipcode')?.value.trim() || '';
    const addrVal = document.getElementById('destAddress')?.value.trim() || '';

    let destParts = [];
    if (addrVal) destParts.push(addrVal);
    if (cityVal) destParts.push(cityVal);
    if (stateVal) destParts.push(stateVal);
    if (zipVal) destParts.push(zipVal);
    destParts.push(c.name + ' (' + c.iata + ')');

    const foreignText = destParts.length > 0 ? destParts.join(', ') : `${c.name} (${c.iata})`;
    const fromText = isExport ? originLabel : foreignText;
    const toText = isExport ? foreignText : originLabel;
    const dirBadge = isExport ? 'Xuất khẩu' : 'Nhập khẩu';
    const weightText = document.getElementById('chargeableWeightVal')?.textContent || '0.00 kg';
    const chosen = state.calculatedResults.find(s => s.code === serviceCodeToUse);
    const priceText = (chosen && chosen.calc.price > 0) ? formatVND(chosen.calc.price) + ' VND' : 'Liên hệ';
    const standardServiceName = getStandardServiceName(serviceCodeToUse, state.direction, state.shipmentType);

    const modalSummary = document.getElementById('modalRouteSummary');
    if (modalSummary) {
      modalSummary.innerHTML = `
        <div style="font-weight: 700; color: #0F172A; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
          <div style="display: flex; align-items: center; gap: 6px;">
            <i class="ph-bold ph-airplane-tilt" style="color: #CE2027; font-size: 14px;" aria-hidden="true"></i>
            <span>Tuyến vận chuyển:</span>
          </div>
          <span style="font-size: 10px; font-weight: 700; background: #EEF2F6; color: #334155; padding: 2px 8px; border-radius: 4px;">${escapeHTML(dirBadge)}</span>
        </div>
        <div style="margin-bottom: 3px;"><strong>Nơi gửi:</strong> ${escapeHTML(fromText)}</div>
        <div style="margin-bottom: 4px;"><strong>Nơi nhận:</strong> ${escapeHTML(toText)}</div>
        <div style="padding-top: 6px; border-top: 1px dashed #CBD5E1; font-size: 11px; color: #64748B;">
          Dịch vụ: <strong style="color: #0F172A;">${escapeHTML(serviceCodeToUse)} (${escapeHTML(SERVICE_REGISTRY[serviceCodeToUse]?.name || '')})</strong> | Cân tính cước: <strong style="color: #0F172A;">${escapeHTML(weightText)}</strong> | Tạm tính: <strong style="color: #CE2027;">${escapeHTML(priceText)}</strong>
        </div>
      `;
    }

    // Populate hidden fields for FluentForm & Quote Logs mapping
    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.value = (val !== null && val !== undefined) ? String(val) : '';
    };
    setVal('bookingService', standardServiceName);
    setVal('bookingHiddenServiceName', `Dịch vụ chuyển phát quốc tế UPS - ${dirBadge}`);
    setVal('bookingDirection', state.direction);
    setVal('bookingDirectionLabel', dirBadge);
    setVal('bookingOrigin', fromText);
    setVal('bookingDestination', toText);
    setVal('bookingDestinationIata', c.iata || '');
    setVal('bookingServiceCode', serviceCodeToUse);
    setVal('bookingServiceName', SERVICE_REGISTRY[serviceCodeToUse]?.name || serviceCodeToUse);
    setVal('bookingChargeableWeight', weightText);
    setVal('bookingTotalPrice', priceText);
    setVal('bookingTotalPriceRaw', chosen?.calc?.price || 0);
    setVal('bookingQuoteLogId', state.lastQuoteLogId || '');
    setVal('bookingRouteSummary', `${fromText} ➔ ${toText}`);
    setVal('bookingPiecesJson', JSON.stringify(state.pieces || []));
    setVal('bookingHiddenSource', 'ups_quote_booking_modal');

    const noticeEl = document.getElementById('bookingNotice');
    if (noticeEl) {
      noticeEl.className = 'hidden mb-3 p-3 rounded-xl text-xs font-semibold';
      noticeEl.innerHTML = '';
    }

    const modal = document.getElementById('bookingModal');
    if (modal) modal.classList.add('open');
  }

  function handleBookingSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();

    const form = document.getElementById('upsBookingForm') || (e?.target);
    const nameInput = document.getElementById('bookingName');
    const phoneInput = document.getElementById('bookingPhone');
    const notesInput = document.getElementById('bookingNotes');
    const submitBtn = document.getElementById('btnSubmitBooking') || form?.querySelector('.btn-calculate');
    const noticeEl = document.getElementById('bookingNotice');

    const name = nameInput?.value.trim() || '';
    const phone = phoneInput?.value.trim() || '';
    const notes = notesInput?.value.trim() || '';

    if (!name || !phone) {
      if (noticeEl) {
        noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-red-50 text-red-700 border border-red-200 block';
        noticeEl.textContent = 'Vui lòng điền đầy đủ họ tên và số điện thoại liên hệ.';
      }
      return;
    }

    const normalizedPhone = phone.trim().replace(/[\s.\-()]/g, '');
    const vnPhoneRegex = /^(?:\+84|84|0)\d{9}$/;
    if (!vnPhoneRegex.test(normalizedPhone)) {
      if (noticeEl) {
        noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-red-50 text-red-700 border border-red-200 block';
        noticeEl.textContent = 'Số điện thoại không hợp lệ (hỗ trợ đầu số 0, 84 hoặc +84 và 9 chữ số tiếp theo).';
      }
      return;
    }

    if (submitBtn) {
      submitBtn.classList.add('loading');
      submitBtn.disabled = true;
    }

    const c = state.selectedCountry || { name: 'United States', iata: 'US' };
    const provSelect = document.getElementById('originProvince');
    const originLabel = state.originProvince || document.getElementById('originProvinceDisplay')?.value || provSelect?.value || 'TP. Hồ Chí Minh';
    const isExport = state.direction === 'export';
    const foreignText = document.getElementById('bookingDestination')?.value || `${c.name} (${c.iata})`;
    const fromText = isExport ? originLabel : foreignText;
    const toText = isExport ? foreignText : originLabel;
    const weightText = document.getElementById('chargeableWeightVal')?.textContent || '0.00 kg';
    const chosen = state.calculatedResults.find(s => s.code === state.service);
    const priceText = (chosen && chosen.calc.price > 0) ? formatVND(chosen.calc.price) + ' VND' : 'Liên hệ';
    const standardServiceName = getStandardServiceName(state.service, state.direction, state.shipmentType);

    const fullMessage = buildBookingDetailedMessage(
      notes,
      isExport,
      fromText,
      toText,
      standardServiceName,
      weightText,
      priceText,
      state.pieces
    );
    const formattedMsgInput = document.getElementById('bookingFormattedMessage');
    if (formattedMsgInput) formattedMsgInput.value = fullMessage;

    const leadPayload = {
      name: name,
      phone: normalizedPhone,
      notes: notes,
      message: fullMessage,
      direction: state.direction,
      service: standardServiceName,
      service_code: state.service,
      service_name: SERVICE_REGISTRY[state.service]?.name || state.service,
      hidden_service_name: `Dịch vụ chuyển phát quốc tế UPS - ${isExport ? 'Xuất khẩu' : 'Nhập khẩu'}`,
      origin: fromText,
      destination: toText,
      destination_iata: c.iata || '',
      destination_name: c.name || '',
      zone: chosen?.calc?.zone || chosen?.zone || '',
      rate_zone: chosen?.calc?.rate_zone || '',
      rate_card_id: chosen?.calc?.rate_card_id || state.rateCardId || 0,
      actual_weight_kg: chosen?.calc?.actual_weight_kg || null,
      dim_weight_kg: chosen?.calc?.dim_weight_kg || null,
      chargeable_weight: weightText,
      total_price: priceText,
      total_price_vnd: chosen?.calc?.price || chosen?.calc?.total_price_vnd || 0,
      total_price_raw: chosen?.calc?.price || chosen?.calc?.total_price_vnd || 0,
      quote_log_id: state.lastQuoteLogId || 0,
      route_summary: `${fromText} ➔ ${toText}`,
      pieces: state.pieces || [],
      hidden_source: 'ups_quote_booking_modal'
    };

    const endpoint = (state.config.apiBase || '/wp-json/ups-quote/v1') + '/lead';

    fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': state.config.nonce || ''
      },
      body: JSON.stringify(leadPayload)
    })
    .then(async res => {
      const data = await res.json().catch(() => ({}));
      if (!res.ok || data.code) {
        throw new Error(data.message || 'Gửi yêu cầu không thành công. Vui lòng thử lại.');
      }
      return data;
    })
    .then(data => {
      if (submitBtn) {
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
      }

      const formState = document.getElementById('bookingModalFormState');
      const successState = document.getElementById('bookingModalSuccessState');
      const summaryCard = document.getElementById('bookingSuccessSummaryCard');

      if (summaryCard) {
        const dirBadge = isExport ? 'Xuất khẩu' : 'Nhập khẩu';
        summaryCard.innerHTML = `
          <div style="padding-bottom: 8px; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
            <span style="color: #64748B; font-weight: 500;">Tuyến vận chuyển:</span>
            <span style="font-weight: 700; color: #0F172A; text-align: right;">${escapeHTML(fromText)} ➔ ${escapeHTML(toText)} (${escapeHTML(dirBadge)})</span>
          </div>
          <div style="padding: 8px 0; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
            <span style="color: #64748B; font-weight: 500;">Gói dịch vụ:</span>
            <span style="font-weight: 700; color: #0F172A; text-align: right;">${escapeHTML(state.service)} (${escapeHTML(SERVICE_REGISTRY[state.service]?.name || '')})</span>
          </div>
          <div style="padding: 8px 0; border-bottom: 1px solid #E2E8F0; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
            <span style="color: #64748B; font-weight: 500;">Cước tạm tính:</span>
            <span style="font-weight: 800; color: #CE2027; text-align: right;">${escapeHTML(priceText)} <span style="font-size: 11px; font-weight: 600; color: #64748B;">(${escapeHTML(weightText)})</span></span>
          </div>
          <div style="padding-top: 8px; display: flex; align-items: center; justify-content: space-between; gap: 8px;">
            <span style="color: #64748B; font-weight: 500;">Khách hàng tiếp nhận:</span>
            <span style="font-weight: 700; color: #047857; text-align: right;">${escapeHTML(name)} · ${escapeHTML(phone)}</span>
          </div>
        `;
      }

      if (formState && successState) {
        formState.classList.add('hidden');
        successState.classList.remove('hidden');
      } else if (noticeEl) {
        noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-emerald-50 text-emerald-800 border border-emerald-200 block';
        noticeEl.innerHTML = '<i class="ph-bold ph-check-circle mr-1"></i> Cảm ơn bạn! Yêu cầu tư vấn & đặt chỗ đã được gửi thành công. Chuyên viên Allship sẽ liên hệ trong 5-10 phút.';
      }

      if (nameInput) nameInput.value = '';
      if (phoneInput) phoneInput.value = '';
      if (notesInput) notesInput.value = '';
    })
    .catch(err => {
      if (submitBtn) {
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
      }
      if (noticeEl) {
        noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-red-50 text-red-700 border border-red-200 block';
        noticeEl.textContent = err.message || 'Có lỗi xảy ra khi gửi yêu cầu. Vui lòng liên hệ hotline 1900 252 338.';
      }
    });
  }

  // ===== J2. PDF QUOTATION EXPORT MODAL & LEAD CAPTURE =====
  let pdfProgressInterval = null;

  function updatePdfProgressBar(percent, text, stepIdx) {
    const bar = document.getElementById('pdfExportProgressBar');
    const pctEl = document.getElementById('pdfExportProgressPercent');
    const textEl = document.getElementById('pdfExportProgressStepText');
    const p = Math.min(100, Math.max(0, Math.round(percent)));

    if (bar) bar.style.width = p + '%';
    if (pctEl) pctEl.textContent = p + '%';
    if (textEl && text) textEl.textContent = text;

    for (let i = 1; i <= 3; i++) {
      const stepEl = document.getElementById('pdfStep' + i);
      if (stepEl) {
        if (i <= stepIdx) {
          stepEl.className = 'transition-colors duration-200 text-brand-red font-bold flex items-center justify-center gap-1';
        } else {
          stepEl.className = 'transition-colors duration-200 text-slate-400 font-semibold flex items-center justify-center gap-1';
        }
      }
    }
  }

  function startPdfProgressAnimation() {
    if (pdfProgressInterval) clearInterval(pdfProgressInterval);
    updatePdfProgressBar(18, 'Đang chuẩn bị dữ liệu tuyến vận chuyển...', 1);

    let current = 18;
    const startTime = Date.now();

    pdfProgressInterval = setInterval(() => {
      const elapsed = Date.now() - startTime;
      if (elapsed < 300) {
        current = Math.min(48, 18 + Math.round((elapsed / 300) * 30));
        updatePdfProgressBar(current, 'Đang tổng hợp thông tin cước & phụ phí...', 1);
      } else if (elapsed < 800) {
        const stageRatio = (elapsed - 300) / 500;
        current = Math.min(86, 48 + Math.round(stageRatio * 38));
        updatePdfProgressBar(current, 'Đang biên dịch bảng báo giá PDF & con dấu điện tử...', 2);
      } else {
        if (current < 97) current += 0.25;
        updatePdfProgressBar(Math.round(current), 'Đang hoàn tất tài liệu & kích hoạt bản sao...', 3);
      }
    }, 40);
  }

  function finishPdfProgressAnimation(callback) {
    if (pdfProgressInterval) {
      clearInterval(pdfProgressInterval);
      pdfProgressInterval = null;
    }
    updatePdfProgressBar(100, 'Hoàn tất! Báo giá PDF sẵn sàng tải về...', 3);
    setTimeout(() => {
      if (typeof callback === 'function') callback();
    }, 320);
  }

  function stopPdfProgressAnimation() {
    if (pdfProgressInterval) {
      clearInterval(pdfProgressInterval);
      pdfProgressInterval = null;
    }
    updatePdfProgressBar(0, '', 0);
  }

  function resetPdfExportModalState() {
    stopPdfProgressAnimation();
    const formState = document.getElementById('pdfExportFormState');
    const loadingState = document.getElementById('pdfExportLoadingState');
    const successState = document.getElementById('pdfExportSuccessState');
    const noticeEl = document.getElementById('pdfExportNotice');
    const form = document.getElementById('upsPdfExportForm');
    const submitBtn = document.getElementById('btnSubmitPdfExport');

    if (formState) formState.classList.remove('hidden');
    if (loadingState) loadingState.classList.add('hidden');
    if (successState) successState.classList.add('hidden');
    if (noticeEl) {
      noticeEl.className = 'hidden mb-3 p-3 rounded-xl text-xs font-semibold';
      noticeEl.innerHTML = '';
    }
    if (submitBtn) {
      submitBtn.classList.remove('loading');
      submitBtn.disabled = false;
    }
    if (form) form.reset();
  }

  function openPdfExportModal(targetServiceCode = null) {
    resetPdfExportModalState();
    const serviceCodeToUse = targetServiceCode || state.service;
    const isExport = state.direction === 'export';
    const provSelect = document.getElementById('originProvince');
    const originLabel = state.originProvince || document.getElementById('originProvinceDisplay')?.value || provSelect?.value || 'TP. Hồ Chí Minh';
    const c = state.selectedCountry || { name: 'United States', iata: 'US' };

    const stateSelect = document.getElementById('destState');
    const stateVal = stateSelect && stateSelect.value && !stateSelect.disabled && stateSelect.options && stateSelect.selectedIndex >= 0
      ? (stateSelect.options[stateSelect.selectedIndex]?.getAttribute('data-name') || stateSelect.value)
      : (stateSelect?.value || '');
    const citySelect = document.getElementById('destCity');
    const cityVal = citySelect && citySelect.value && citySelect.value !== 'other' ? citySelect.value : '';
    const zipVal = document.getElementById('destZipcode')?.value.trim() || '';
    const addrVal = document.getElementById('destAddress')?.value.trim() || '';

    let destParts = [];
    if (addrVal) destParts.push(addrVal);
    if (cityVal) destParts.push(cityVal);
    if (stateVal) destParts.push(stateVal);
    if (zipVal) destParts.push(zipVal);
    destParts.push(c.name + ' (' + c.iata + ')');

    const foreignText = destParts.length > 0 ? destParts.join(', ') : `${c.name} (${c.iata})`;
    const fromText = isExport ? originLabel : foreignText;
    const toText = isExport ? foreignText : originLabel;
    const dirBadge = isExport ? 'Xuất khẩu' : 'Nhập khẩu';
    const weightText = document.getElementById('chargeableWeightVal')?.textContent || '0.00 kg';
    const weightNum = parseFloat(weightText) || 0;
    const chosen = state.calculatedResults.find(s => s.code === serviceCodeToUse);
    const totalPrice = (chosen && chosen.calc.price > 0) ? chosen.calc.price : 0;
    const priceText = totalPrice > 0 ? formatVND(totalPrice) + ' VND' : 'Liên hệ';
    const serviceDisplayName = SERVICE_REGISTRY[serviceCodeToUse]?.name || serviceCodeToUse;

    const modalSummary = document.getElementById('pdfModalRouteSummary');
    if (modalSummary) {
      modalSummary.innerHTML = `
        <div style="font-weight: 700; color: #0F172A; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid #E2E8F0; padding-bottom: 8px;">
          <div style="display: flex; align-items: center; gap: 8px;">
            <div style="width: 24px; height: 24px; border-radius: 6px; background: #FEE2E2; color: #CE2027; display: flex; align-items: center; justify-content: center; font-size: 13px;">
              <i class="ph-bold ph-airplane-tilt" aria-hidden="true"></i>
            </div>
            <span style="font-size: 13px; font-weight: 800;">Tuyến: ${escapeHTML(fromText)} ➔ ${escapeHTML(toText)}</span>
          </div>
          <span style="font-size: 10px; font-weight: 800; background: #EEF2F6; color: #334155; padding: 3px 8px; border-radius: 6px; border: 1px solid #E2E8F0;">${escapeHTML(dirBadge)}</span>
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; padding-top: 6px; color: #475569;">
          <span>Gói dịch vụ: <strong style="color: #0F172A; font-weight: 800;">UPS ${escapeHTML(serviceCodeToUse)} (${escapeHTML(serviceDisplayName)})</strong></span>
          <span>Cân tính cước: <strong style="color: #0F172A; font-weight: 800; font-family: monospace;">${escapeHTML(weightText)}</strong></span>
        </div>
        <div style="display: flex; align-items: center; justify-content: space-between; font-size: 12px; padding-top: 4px;">
          <span style="color: #64748B;">Tổng cước tạm tính (bao gồm phụ phí):</span>
          <span style="font-size: 15px; font-weight: 900; color: #CE2027; font-family: monospace;">${escapeHTML(priceText)}</span>
        </div>
      `;
    }

    // Set hidden fields
    const setVal = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.value = (val !== null && val !== undefined) ? String(val) : '';
    };
    setVal('pdfExportServiceCode', serviceCodeToUse);
    setVal('pdfExportServiceName', `UPS ${serviceCodeToUse} - ${serviceDisplayName}`);
    setVal('pdfExportDirection', state.direction);
    setVal('pdfExportDestinationIata', c.iata || '');
    setVal('pdfExportDestinationName', c.name || '');
    setVal('pdfExportWeightKg', weightNum);
    setVal('pdfExportTotalPriceVnd', totalPrice);
    setVal('pdfExportBasePriceVnd', chosen?.calc?.base_price || chosen?.calc?.price || totalPrice);
    setVal('pdfExportQuoteLogId', state.lastQuoteLogId || '');

    const modal = document.getElementById('quotePdfExportModal');
    if (modal) {
      modal.classList.add('open');
      setTimeout(() => {
        const nameInput = document.getElementById('pdfExportName');
        if (nameInput) nameInput.focus();
      }, 60);
    }
  }

  function handlePdfExportSubmit(e) {
    if (e && e.preventDefault) e.preventDefault();

    const nameInput = document.getElementById('pdfExportName');
    const companyInput = document.getElementById('pdfExportCompany');
    const emailInput = document.getElementById('pdfExportEmail');
    const phoneInput = document.getElementById('pdfExportPhone');
    const notesInput = document.getElementById('pdfExportNotes');
    const submitBtn = document.getElementById('btnSubmitPdfExport');
    const noticeEl = document.getElementById('pdfExportNotice');

    const name = nameInput?.value.trim() || '';
    const company = companyInput?.value.trim() || '';
    const email = emailInput?.value.trim() || '';
    const phone = phoneInput?.value.trim() || '';
    const notes = notesInput?.value.trim() || '';

    if (!name) {
      if (noticeEl) {
        noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-red-50 text-red-700 border border-red-200 block';
        noticeEl.textContent = 'Vui lòng nhập họ và tên của bạn.';
      }
      return;
    }

    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email || !emailRegex.test(email)) {
      if (noticeEl) {
        noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-red-50 text-red-700 border border-red-200 block';
        noticeEl.textContent = 'Vui lòng nhập địa chỉ email hợp lệ để nhận file báo giá PDF.';
      }
      return;
    }

    if (phone) {
      const cleanPhone = phone.replace(/[\s\-\.\(\)]/g, '');
      const phoneRegex = /^(\+?[0-9]{8,15})$/;
      if (!phoneRegex.test(cleanPhone)) {
        if (noticeEl) {
          noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-red-50 text-red-700 border border-red-200 block';
          noticeEl.textContent = 'Số điện thoại không hợp lệ (từ 8 đến 15 chữ số).';
        }
        return;
      }
    }

    if (submitBtn) {
      submitBtn.classList.add('loading');
      submitBtn.disabled = true;
    }

    const formState = document.getElementById('pdfExportFormState');
    const loadingState = document.getElementById('pdfExportLoadingState');
    const successState = document.getElementById('pdfExportSuccessState');

    if (formState) formState.classList.add('hidden');
    if (loadingState) loadingState.classList.remove('hidden');
    if (successState) successState.classList.add('hidden');
    startPdfProgressAnimation();

    const getVal = (id) => document.getElementById(id)?.value || '';
    const serviceCode = getVal('pdfExportServiceCode') || state.service;
    const serviceName = getVal('pdfExportServiceName') || (SERVICE_REGISTRY[serviceCode]?.name || serviceCode);
    const chosen = state.calculatedResults.find(s => s.code === serviceCode);
    const totalPrice = parseInt(getVal('pdfExportTotalPriceVnd'), 10) || (chosen?.calc?.price || 0);
    const basePrice = parseInt(getVal('pdfExportBasePriceVnd'), 10) || totalPrice;
    const weightKg = parseFloat(getVal('pdfExportWeightKg')) || 0;

    const exportPayload = {
      name: name,
      contact_name: name,
      company_name: company,
      email: email,
      phone: phone,
      notes: notes,
      service_code: serviceCode,
      service_name: serviceName,
      direction: getVal('pdfExportDirection') || state.direction,
      destination_iata: getVal('pdfExportDestinationIata') || (state.selectedCountry?.iata || 'US'),
      destination_name: getVal('pdfExportDestinationName') || (state.selectedCountry?.name || 'United States'),
      chargeable_weight_kg: weightKg,
      total_price_vnd: totalPrice,
      base_price_vnd: basePrice,
      pieces: state.pieces || [],
      quote_log_id: parseInt(getVal('pdfExportQuoteLogId'), 10) || state.lastQuoteLogId || 0,
      send_email: true
    };

    const endpoint = (state.config.apiBase || '/wp-json/ups-quote/v1') + '/export-quote';

    fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': state.config.nonce || ''
      },
      body: JSON.stringify(exportPayload)
    })
    .then(async res => {
      const json = await res.json().catch(() => ({}));
      if (!res.ok || json.success === false) {
        throw new Error(json.error?.message || json.message || 'Không thể tạo file báo giá. Vui lòng thử lại.');
      }
      return json.data || json;
    })
    .then(data => {
      finishPdfProgressAnimation(() => {
        if (loadingState) loadingState.classList.add('hidden');
        if (formState) formState.classList.add('hidden');

        if (submitBtn) {
          submitBtn.classList.remove('loading');
          submitBtn.disabled = false;
        }

        if (data && data.quote_log_id) {
          state.lastQuoteLogId = data.quote_log_id;
          const logInput = document.getElementById('pdfExportQuoteLogId');
          if (logInput) logInput.value = data.quote_log_id;
        }

        const successState = document.getElementById('pdfExportSuccessState');
        const summaryCard = document.getElementById('pdfSuccessSummaryCard');
        const directDlLink = document.getElementById('pdfDirectDownloadLink');

        const downloadUrl = data.download_url || data.pdf_url || '';
        const quoteRef = data.quote_ref || 'AS-QUO';

        // 1. Programmatic auto-download trigger
        if (downloadUrl) {
          try {
            const dlAnchor = document.createElement('a');
            dlAnchor.href = downloadUrl;
            dlAnchor.download = `${quoteRef}.pdf`;
            dlAnchor.target = '_blank';
            document.body.appendChild(dlAnchor);
            dlAnchor.click();
            setTimeout(() => dlAnchor.remove(), 200);
          } catch (err) {
            console.warn('Auto download prevented by browser:', err);
          }
        }

        // 2. Direct download button href update
        if (directDlLink) {
          directDlLink.href = downloadUrl || '#';
          directDlLink.download = `${quoteRef}.pdf`;
        }

        // 3. Populate confirmation details card
        if (summaryCard) {
          summaryCard.innerHTML = `
            <div style="display: flex; align-items: center; justify-content: space-between; padding-bottom: 8px; border-bottom: 1px solid #E2E8F0;">
              <span style="color: #64748B;">Mã báo giá chính thức:</span>
              <span style="font-family: monospace; font-weight: 800; font-size: 13px; color: #CE2027; background: #FEE2E2; padding: 2px 8px; border-radius: 4px;">${escapeHTML(quoteRef)}</span>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #E2E8F0;">
              <span style="color: #64748B;">Email người nhận:</span>
              <strong style="color: #0F172A;">${escapeHTML(email)}</strong>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #E2E8F0;">
              <span style="color: #64748B;">Đại diện doanh nghiệp:</span>
              <strong style="color: #0F172A;">${escapeHTML(name)}${company ? ' (' + escapeHTML(company) + ')' : ''}</strong>
            </div>
            <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 6px;">
              <span style="color: #64748B;">Gói dịch vụ & Cước:</span>
              <strong style="color: #047857;">${escapeHTML(serviceName)} — ${formatVND(totalPrice)} VND</strong>
            </div>
          `;
        }

        if (successState) {
          successState.classList.remove('hidden');
        }
      });
    })
    .catch(err => {
      stopPdfProgressAnimation();
      const loadingState = document.getElementById('pdfExportLoadingState');
      const formState = document.getElementById('pdfExportFormState');
      if (loadingState) loadingState.classList.add('hidden');
      if (formState) formState.classList.remove('hidden');

      if (submitBtn) {
        submitBtn.classList.remove('loading');
        submitBtn.disabled = false;
      }
      if (noticeEl) {
        noticeEl.className = 'mb-3 p-3 rounded-xl text-xs font-semibold bg-red-50 text-red-700 border border-red-200 block';
        noticeEl.textContent = err.message || 'Đã có lỗi xảy ra trong quá trình xuất PDF. Vui lòng thử lại sau.';
      }
    });
  }

  // ===== K. PIECES DETAIL MODAL =====
  function openPiecesDetailModal() {
    const tbody = document.getElementById('piecesModalTableBody');
    if (!tbody) return;

    let totalQty = 0;
    let totalAct = 0;
    let totalDim = 0;
    let totalChg = 0;
    const divisor = state.config.dim_divisor || 5500;
    const step = state.config.rounding_step || 0.5;

    tbody.innerHTML = state.pieces.map((p, idx) => {
      const qty = Math.max(1, p.qty || 1);
      const weight = Number(p.weight) || 0;
      const l = Number(p.len) || 0;
      const w = Number(p.wid) || 0;
      const h = Number(p.hei) || 0;
      const dimPer = (l * w * h) / divisor;
      const chPer = ceilToHalf(Math.max(weight, dimPer), step);
      const lineCh = chPer * qty;

      totalQty += qty;
      totalAct += weight * qty;
      totalDim += dimPer * qty;
      totalChg += lineCh;

      const dimStr = (l > 0 && w > 0 && h > 0) ? `${l} × ${w} × ${h} cm` : '<span class="text-slate-400 italic">Chưa nhập</span>';
      const isDimApplied = dimPer > weight;

      return `
        <tr class="hover:bg-slate-50/80 transition-colors">
          <td class="py-2.5 px-3 font-bold text-navy-900">Kiện #${idx + 1}</td>
          <td class="py-2.5 px-3 text-center font-bold">${qty}</td>
          <td class="py-2.5 px-3 text-center font-mono text-[11px]">${dimStr}</td>
          <td class="py-2.5 px-3 text-right font-medium ${!isDimApplied ? 'text-navy-900 font-bold' : 'text-slate-500'}">${weight.toFixed(2)} kg</td>
          <td class="py-2.5 px-3 text-right font-medium ${isDimApplied ? 'text-navy-900 font-bold' : 'text-slate-500'}">${dimPer > 0 ? dimPer.toFixed(2) + ' kg' : '—'}</td>
          <td class="py-2.5 px-3 text-right font-bold text-navy-900">${chPer.toFixed(1)} kg</td>
          <td class="py-2.5 px-3 text-right font-black text-brand-red">${lineCh.toFixed(1)} kg</td>
        </tr>
      `;
    }).join('');

    const elTotalQty = document.getElementById('modalTotalQty');
    const elTotalAct = document.getElementById('modalTotalActual');
    const elTotalDim = document.getElementById('modalTotalDim');
    const elTotalChg = document.getElementById('modalTotalChargeable');

    if (elTotalQty) elTotalQty.textContent = totalQty;
    if (elTotalAct) elTotalAct.textContent = totalAct.toFixed(2) + ' kg';
    if (elTotalDim) elTotalDim.textContent = totalDim.toFixed(2) + ' kg';
    if (elTotalChg) elTotalChg.textContent = totalChg.toFixed(1) + ' kg';

    const modal = document.getElementById('piecesDetailModal');
    if (modal) modal.classList.add('open');
  }

  function closeModals() {
    const booking = document.getElementById('bookingModal');
    const piecesModal = document.getElementById('piecesDetailModal');
    const pdfModal = document.getElementById('quotePdfExportModal');
    if (booking) booking.classList.remove('open');
    if (piecesModal) piecesModal.classList.remove('open');
    if (pdfModal) pdfModal.classList.remove('open');
    resetBookingModalState();
    resetPdfExportModalState();
  }

  // ===== L. MOBILE STICKY ACTION BAR & VIEW TOGGLE =====
  function setMobileResultView(mode) {
    state.mobileViewMode = mode;
    const btnCompact = document.getElementById('btnMobileViewCompact');
    const btnCards = document.getElementById('btnMobileViewCards');
    const compContainer = document.getElementById('mobileCompactListContainer');
    const gridContainer = document.getElementById('serviceCardsGrid');

    if (mode === 'compact') {
      if (btnCompact) btnCompact.className = 'px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-navy-900 shadow-xs cursor-pointer';
      if (btnCards) btnCards.className = 'px-2.5 py-1 rounded-lg text-xs font-bold text-slate-500 hover:text-navy-900 cursor-pointer';
      if (compContainer) compContainer.style.display = 'block';
      if (gridContainer) {
        gridContainer.classList.add('hidden');
        gridContainer.classList.remove('grid');
      }
    } else {
      if (btnCards) btnCards.className = 'px-2.5 py-1 rounded-lg text-xs font-bold bg-white text-navy-900 shadow-xs cursor-pointer';
      if (btnCompact) btnCompact.className = 'px-2.5 py-1 rounded-lg text-xs font-bold text-slate-500 hover:text-navy-900 cursor-pointer';
      if (compContainer) compContainer.style.display = 'none';
      if (gridContainer) {
        gridContainer.classList.remove('hidden');
        gridContainer.classList.add('grid');
      }
    }
  }

  function selectServiceFromMobileList(code) {
    selectService(code);
    executeCalculation();
  }

  function updateStickyBar() {
    const stickyBar = document.getElementById('mobileStickyActionBar');
    if (!stickyBar) return;

    const chosen = state.calculatedResults.find(s => s.code === state.service);
    const serviceNameEl = document.getElementById('stickyChosenServiceName');
    const servicePriceEl = document.getElementById('stickyChosenServicePrice');

    if (chosen && chosen.calc.price > 0) {
      if (serviceNameEl) serviceNameEl.textContent = 'UPS ' + chosen.name;
      if (servicePriceEl) servicePriceEl.textContent = formatVND(chosen.calc.price) + ' đ';
      stickyBar.classList.add('is-active');
    } else if (chosen) {
      if (serviceNameEl) serviceNameEl.textContent = 'UPS ' + chosen.name;
      if (servicePriceEl) servicePriceEl.textContent = 'Liên hệ';
      stickyBar.classList.add('is-active');
    } else {
      stickyBar.classList.remove('is-active');
    }
  }

  function scrollToForm() {
    const el = document.getElementById('quoteFormCard');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  // ===== INITIALIZATION =====
  function init() {
    if (typeof window.upsQuoteConfig !== 'undefined') {
      Object.assign(state.config, window.upsQuoteConfig);
    }

    if (state.config.dim_divisor) {
      const heroDimEl = document.getElementById('heroDimDivisor');
      if (heroDimEl) heroDimEl.textContent = state.config.dim_divisor;
      const modalDimEl = document.getElementById('modalDimDivisor');
      if (modalDimEl) modalDimEl.textContent = state.config.dim_divisor;
    }

    // Leave destination country & address blank for user selection
    const countries = state.config.COUNTRIES || [];
    state.selectedCountry = null;
    state.selectedState = '';
    state.selectedCity = '';

    // Initialize Origin Province
    state.originProvince = 'TP. Hồ Chí Minh';
    const originDisp = document.getElementById('originProvinceDisplay');
    const originHidden = document.getElementById('originProvince');
    if (originDisp) originDisp.value = 'TP. Hồ Chí Minh';
    if (originHidden) {
      if (state.config.VN_PROVINCES && state.config.VN_PROVINCES.length) {
        originHidden.innerHTML = state.config.VN_PROVINCES.map(p => `<option value="${escapeHTML(p)}"${p === 'TP. Hồ Chí Minh' ? ' selected' : ''}>${escapeHTML(p)}</option>`).join('');
      }
      originHidden.value = 'TP. Hồ Chí Minh';
    }
    renderOriginOptions(state.config.VN_PROVINCES);

    renderCountryOptions(countries);
    const countryDisp = document.getElementById('destCountryDisplay');
    if (countryDisp) {
      countryDisp.value = '';
      countryDisp.placeholder = 'Tìm quốc gia hoặc mã IATA...';
    }

    const stateHidden = document.getElementById('destState');
    const stateDisplay = document.getElementById('destStateDisplay');
    if (stateHidden) stateHidden.value = '';
    if (stateDisplay) {
      stateDisplay.value = '';
      stateDisplay.placeholder = '— Chọn quốc gia trước —';
      if (stateDisplay.parentElement && stateDisplay.parentElement.classList) {
        stateDisplay.parentElement.classList.add('opacity-60', 'pointer-events-none');
      }
    }

    const cityHidden = document.getElementById('destCity');
    const cityDisplay = document.getElementById('destCityDisplay');
    if (cityHidden) cityHidden.value = '';
    if (cityDisplay) {
      cityDisplay.value = '';
      cityDisplay.placeholder = '— Chọn quốc gia trước —';
      if (cityDisplay.parentElement && cityDisplay.parentElement.classList) {
        cityDisplay.parentElement.classList.add('opacity-60', 'pointer-events-none');
      }
    }

    const zipInput = document.getElementById('destZipcode');
    if (zipInput) zipInput.value = '';

    // Initial pieces
    state.pieces = [
      { id: state.pieceIdCounter++, qty: 1, weight: 3.2, len: 30, wid: 20, hei: 15 },
      { id: state.pieceIdCounter++, qty: 2, weight: 1.1, len: 25, wid: 20, hei: 10 }
    ];
    renderPieces();
    recalculateMetrics();

    // Close all combobox dropdowns and modals on Escape
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        toggleCountryDropdown(false);
        toggleOriginDropdown(false);
        toggleStateDropdown(false);
        toggleCityDropdown(false);
        closeModals();
      }
    });

    document.addEventListener('click', (e) => {
      const cb = document.getElementById('countryCombobox');
      if (cb && !cb.contains(e.target)) toggleCountryDropdown(false);

      const ob = document.getElementById('originCombobox');
      if (ob && !ob.contains(e.target)) toggleOriginDropdown(false);

      const sb = document.getElementById('stateCombobox');
      if (sb && !sb.contains(e.target)) toggleStateDropdown(false);

      const cib = document.getElementById('cityCombobox');
      if (cib && !cib.contains(e.target)) toggleCityDropdown(false);

      // Close modal on backdrop click
      const pdfModal = document.getElementById('quotePdfExportModal');
      if (pdfModal && e.target === pdfModal) closeModals();
      const piecesModal = document.getElementById('piecesDetailModal');
      if (piecesModal && e.target === piecesModal) closeModals();
    });

    // Cập nhật ngày hiệu lực theo thời gian thực (bypass HTML cache)
    updateRealtimeNoticeDate();

    // Hydrate initial services availability from localized config or sync via REST
    if (Array.isArray(state.config.INITIAL_SERVICES) && state.config.INITIAL_SERVICES.length > 0) {
      updateServicesUI(state.config.INITIAL_SERVICES);
    } else {
      updateCategoryTabCounts();
      filterCategory(state.categoryFilter);
    }
    syncServicesAvailability(state.direction);
  }

  function updateRealtimeNoticeDate() {
    const el = document.getElementById('quoteNoticeDate');
    if (!el) return;
    const now = new Date();
    const d = String(now.getDate()).padStart(2, '0');
    const m = String(now.getMonth() + 1).padStart(2, '0');
    const y = now.getFullYear();
    el.textContent = `${d}/${m}/${y}`;
  }

  // ===== PUBLIC API / EXPORT =====
  const UPSQuote = {
    SERVICE_REGISTRY,
    RATE_TABLES,
    MAJOR_CITIES_BY_STATE,
    VN_COUNTRY_ALIASES,
    removeVietnameseTones,
    state,
    formatVND,
    ceilToHalf,
    escapeHTML,
    showError,
    hideError,
    selectDirection,
    filterCategory,
    selectService,
    updateServicesUI,
    adjustGridColumns,
    updateCategoryTabCounts,
    syncServicesAvailability,
    setShipmentType,
    toggleOriginDropdown,
    selectOriginProvince,
    filterOriginProvinces,
    toggleCountryDropdown,
    filterCountries,
    selectCountry,
    toggleStateDropdown,
    selectState,
    filterStates,
    toggleCityDropdown,
    selectCity,
    filterCities,
    updateDestinationAddressFields,
    onStateChange,
    onCityChange,
    renderPieces,
    addNewPiece,
    removePiece,
    updatePiece,
    recalculateMetrics,
    lookupRate,
    lookupFreightRate,
    performCalculation,
    executeCalculation,
    showCalculationLoading,
    hideCalculationLoading,
    setMobileResultView,
    selectServiceFromMobileList,
    updateStickyBar,
    openBookingModal,
    resetBookingModalState,
    handleBookingSubmit,
    openPdfExportModal,
    resetPdfExportModalState,
    handlePdfExportSubmit,
    openPiecesDetailModal,
    closeModals,
    scrollToForm,
    init
  };

  window.UPSQuote = UPSQuote;

  // Window-level bindings for inline onclick attributes
  window.selectDirection = selectDirection;
  window.filterCategory = filterCategory;
  window.selectService = selectService;
  window.updateServicesUI = updateServicesUI;
  window.adjustGridColumns = adjustGridColumns;
  window.updateCategoryTabCounts = updateCategoryTabCounts;
  window.syncServicesAvailability = syncServicesAvailability;
  window.setShipmentType = setShipmentType;
  window.toggleOriginDropdown = toggleOriginDropdown;
  window.selectOriginProvince = selectOriginProvince;
  window.filterOriginProvinces = filterOriginProvinces;
  window.toggleCountryDropdown = toggleCountryDropdown;
  window.filterCountries = filterCountries;
  window.selectCountry = selectCountry;
  window.toggleStateDropdown = toggleStateDropdown;
  window.selectState = selectState;
  window.filterStates = filterStates;
  window.toggleCityDropdown = toggleCityDropdown;
  window.selectCity = selectCity;
  window.filterCities = filterCities;
  window.onStateChange = onStateChange;
  window.onCityChange = onCityChange;
  window.addNewPiece = addNewPiece;
  window.removePiece = removePiece;
  window.updatePiece = updatePiece;
  window.performCalculation = performCalculation;
  window.showCalculationLoading = showCalculationLoading;
  window.hideCalculationLoading = hideCalculationLoading;
  window.setMobileResultView = setMobileResultView;
  window.selectServiceFromMobileList = selectServiceFromMobileList;
  window.openBookingModal = openBookingModal;
  window.resetBookingModalState = resetBookingModalState;
  window.handleBookingSubmit = handleBookingSubmit;
  window.openPdfExportModal = openPdfExportModal;
  window.resetPdfExportModalState = resetPdfExportModalState;
  window.handlePdfExportSubmit = handlePdfExportSubmit;
  window.openPiecesDetailModal = openPiecesDetailModal;
  window.closeModals = closeModals;
  window.scrollToForm = scrollToForm;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

})(window, document);
