-- Path: C:\inetpub\wwwroot\teknisk.hkbb.no\docs\sql\oppdater_node_locations_lat_lon_fra_liste.sql
USE `teknisk`;

START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS `tmp_node_location_coords`;

CREATE TEMPORARY TABLE `tmp_node_location_coords` (
  `nodelokasjon` varchar(160) NOT NULL,
  `driftsmerking` varchar(128) DEFAULT NULL,
  `beskrivelse` varchar(255) DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  PRIMARY KEY (`nodelokasjon`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `tmp_node_location_coords` (`nodelokasjon`, `driftsmerking`, `beskrivelse`, `lat`, `lng`) VALUES
('Ådland1', '67958', 'Nodehytte Ådland', 59.79260270, 5.50669840),
('Åkra1', '10023', 'Haugaland - Node hos Åkra Bil (Leiligheter)', 59.26060254, 5.19230387),
('Åkra3', '10057', 'Haugaland - Node i trafokiosk Åkra', 59.26020202, 5.18680313),
('Åkra4', '10056', '', 59.26066200, 5.19451222),
('Åkra5', '10070', 'Haugaland - Nodehytte Åkrasanden', 59.25249562, 5.19689840),
('Åkra6', '10076', 'Haugaland - Nodehytte Jorunnveien', 59.25799672, 5.20549400),
('Åkra7', '10088', 'Haugaland - Nodehytte Tjøsvollvegen', 59.26680385, 5.19119299),
('Åkra8', '10066', 'Haugaland - Nodehytte Tjøsvoll Øst', 59.26405065, 5.21006355),
('Aksdal1', '10046', 'Haugaland - Node i Triangelen', 59.42290057, 5.44840532),
('Åmsos1', '10100', 'Åmsosen sek.stasjon, Vindafjord, Haugaland', 59.47692635, 5.73297481),
('Asalvik1', '10065', 'Haugaland - Node på Østrembroveien i Kopervik', 59.28679932, 5.29290822),
('Asparhaug1', '10008', 'Haugaland - Nodehytte på Asparhaug Tysvær', 59.41275974, 5.40813139),
('Auklandshavn', '10113', 'Auklandshavn, Sveio', 59.63815822, 5.38336727),
('Avaldsnes1', '10084', 'Haugaland - Node på Avaldsnes', 59.35744032, 5.27698050),
('Bakaroy1', '10034', 'Haugaland - Node i kiosk pa Bakaroy', 59.41599786, 5.25560187),
('Bakkevik1', '10128', 'Huawei gateskap', 59.29509704, 5.65120669),
('Bandadalen1', '10131', 'Bandadalen, Stord', 59.77829373, 5.48691361),
('Bjoa', '30004', 'Haugaland - Node i kiosk på Bjoa', 59.66129710, 5.67700852),
('Bjøllehaugen1', '10071', 'Haugaland - Node på Bjøllehaugen', 59.35509787, 5.32310203),
('Bleikemyr1', '10080', 'Haugaland - Node på Nauthaug - Bleikemyr', 59.43578426, 5.28817195),
('Bokn1', '10098', 'Haugaland - Node i Føresvik på Bokn', 59.22940035, 5.44130456),
('Bøtoppen1', '10028', '', 59.37243575, 5.28794101),
('Brakahaug1', '10083', 'Haugaland - Node på Brakahaug', 59.40088994, 5.32127406),
('Buavåg1', '10143', 'Huawei F01H300GR Gateskap', 59.59707832, 5.32096993),
('Dimmelsvik', '20006', 'Dimmelsvik', 59.95300284, 5.97369238),
('Dukkelunden1', '10018', 'Haugaland - Node Dukkelunden', 59.43010149, 5.26370410),
('Ekrene1', '10010', 'Nodehytte Ekrene', 59.48679714, 5.30999765),
('Eldøyane1', '10129', 'Datarom i Kjøtteinsvegen 225 (Akerhus), 5411 Stord', 59.76450081, 5.50370699),
('Erfjord1', 'N-30', 'Haugaland - Node på Erøy i Erfjord', 59.34045881, 6.21831159),
('Fjæra1', '30103', 'Fjæra, Etne', 59.87397181, 6.38101135),
('Fjellberg1', 'N-40', 'Haugaland - Node i Fjellbergskaret, Suldal', 59.39202510, 6.42744390),
('Fjellveien33', '10041', 'Haugaland - Node i Høyblokkene', 59.41089790, 5.29710392),
('Flotmyr1', '10040', '', 59.41681225, 5.27158382),
('Forde1', '10069', 'Haugaland - Kiosk ved barnehage', 59.61080005, 5.47569405),
('Foreneset1', 'N-25', 'Haugaland - Node ved gamle Foreneset skule', 59.33289655, 6.10289926),
('Førlandskrysset1', '10139', 'Node ved nye Førlandskrysset på Stord', 59.83160445, 5.52256656),
('Førre1', '10006', 'Haugaland - Node i Førresdalen - K3', 59.42237185, 5.38998568),
('Førre2', '10097', 'Haugaland - Node i dobbelkiosk ved Førre skole', 59.42866719, 5.38490120),
('Føyno1', '10116', 'Nodehytte på Føyno, Stord', 59.74040130, 5.40180367),
('Gassco1', '', 'Gassco, Bygnes, Haugaland', 59.30050015, 5.30590725),
('Geitafjellet1', '10030', 'Haugaland - Node på Geitafjellet', 59.41640398, 5.28600337),
('Gismarvik1', '10038', 'HNP ved NS-21238', 59.31389860, 5.42910375),
('Grinde1', '10016', 'Haugaland - Node i kiosk Grinde / Garhaug', 59.42600412, 5.46670422),
('Haga1', '51334', 'Node 1Haga', 59.80510243, 5.52179485),
('Hålandsdalen1', 'N-35', 'Haugaland - Hålandsdalen, Suldal', 59.39968903, 6.32149572),
('Hansadalen1', '10105', 'Haugaland - Dobbelkiosk Hansadalen', 59.27469878, 5.20010391),
('Hatland1', '10119', 'Hatlandsdalen, Stord', 59.75528205, 5.44367991),
('Hauge1', '10092', 'Haugaland - Hauge, Haugesund.', 59.42126209, 5.26257529),
('Hebnes', 'N-15', 'Haugaland - Hebnes Kai, Suldal', 59.35769635, 5.95859402),
('Heiane1', '10200', '', 59.76100497, 5.44810626),
('Hellandsbygd', '10135', 'Handelandsvegen 120', 59.68777534, 6.51530914),
('Hervik1', '10082', 'Haugaland - Nodehytte i Hervik, Tysvær', 59.32190299, 5.59030232),
('Hetland1', '10132', 'Nodeskap v/gamle Hetland Skole, Tysvær', 59.33180224, 5.44060239),
('Høievegen', '10121', '', 59.41254347, 5.38986935),
('Huglo', '2120474', 'Nodeskap på Huglo', 59.84679889, 5.57969525),
('hybelsauda', '10107', 'Hybelbygg Torsveien', 59.65060040, 6.36540364),
('Innbjoa', '30007', '', 59.66612418, 5.64340607),
('Isvik2', '10101', 'Haugaland - Node ved kirke Isvik, Skjold', 59.50547814, 5.58994160),
('Jamnhaug1', '10087', 'Haugaland - Nodehytte i Ulvøygata', 59.39079744, 5.31100601),
('Jektavik', '30006', 'Haugaland - Nodehytte ved Westcon, Ølen', 59.60881558, 5.76913357),
('Jelsa1', 'N-20', 'Haugaland - Node i kiosk ved Joker på Jelsa', 59.33872924, 6.03015279),
('Kallevik1', '10020', 'Haugaland - Nodehytte Kallevik Tysvær', 59.36931627, 5.38857045),
('Kårevik1', '61734', 'Nodehytte kårevik', 59.75839907, 5.47079425),
('Karmsund1', '10037', 'Haugaland - Node Karmsund Brygge', 59.40229862, 5.27989944),
('Kårstø1', '10134', 'Møretrafo hytte v/NS-21119 SANDVIK N', 59.28190223, 5.52429733),
('Knutsaåsen1', '10115', 'Knutsaåsen Omsorgssenter', 59.78689601, 5.49439750),
('Kolnes1', '10073', 'Haugaland - Node på Kolnes', 59.39340190, 5.36970355),
('Kopervik1', '10001', 'Haugaland - Node i Kopervik - K3', 59.28289839, 5.30705397),
('Kvala1', '10011', 'Haugaland - Kiosk ved Haugesund-3', 59.43970115, 5.26580244),
('Kvala2', '10068', 'Haugaland - Nodehytte ved kiosk og Sagatun barnehage', 59.43260137, 5.25690771),
('Kvalavåg1', '10103', 'Nodehytte Kvalavåg, Karmøy', 59.32480078, 5.20589520),
('Kvilldal1', 'N-65', 'Haugaland - Kvilldal, Suldal', 59.51560059, 6.63429271),
('Kyrkjeberget', '30002', 'Haugaland - Kyrkjeberget, Ølen', 59.60389711, 5.80440773),
('Langaker1', '10095', 'Haugaland - Nodehytte ved trafokiosk Langåker skole', 59.22249913, 5.19420676),
('Leirvik2', '10123', 'Evjo, Leirvik, Stord, Haugaland', 59.77769881, 5.50069488),
('Liaheia1', '10086', 'Haugaland - Dobbelkiosk på Liaheia ved Tvedt regnskap.', 59.48010012, 5.53380334),
('Litledalen1', '10099', 'Haugaland - Node i kraftstasjonen litledalen', 59.66339811, 6.06520800),
('Løkjen1', '10078', 'Haugaland - Kiosk v Ørpetveitvegen.', 59.40960090, 5.33400299),
('Mågeli', '50013', '', 60.13291274, 6.62795450),
('Marvik1', 'N-10', 'Haugaland - Node på Holmen', 59.42230062, 6.03430790),
('Midtstokke1', '10002', 'Haugaland - Node Midtstokke - K3', 59.25949848, 5.32310564),
('Mo1', 'N-52', 'Haugaland - Node i Trafostasjon Mo', 59.45990196, 6.42340515),
('Moksheim1', '10064', 'Haugaland - Nodehytte Moksheimåsen', 59.36177979, 5.31524770),
('Molnes1', '30101', 'Haugaland - Molnes, Skånevik', 59.72739972, 5.86590817),
('Mølstrevåg1', '10094', 'Haugaland - Node i Mølstrevåg, Sveio', 59.52660272, 5.27710603),
('Moskar1', '10042', 'Haugaland - Node i kiosk, Moskar Vardafjellet', 59.41549884, 5.29900436),
('Mosvatnet1', 'N-45', 'Haugaland - Mosvatnet, Suldal', 59.42113736, 6.44961959),
('Nedstrand1', '10047', 'Node i Hinderåvåg', 59.34903528, 5.79941607),
('Nervik1', 'Nervik Eviny', 'Nervik Vindafjord', 59.67569753, 5.79149198),
('Nesflaten1', 'N-70', 'Haugaland - Nesflaten, Suldal', 59.64543312, 6.80158260),
('Nordbygdene1', '10106', 'Nordbygnede ved NS51230', 59.46029946, 5.27140826),
('Norheim1', '10077', 'Haugaland - Node i Norheimskogen', 59.37756486, 5.31603926),
('Nysæter1', '83827', 'Nodehytte Nysæter', 59.78430024, 5.40040006),
('Odda10 Røldal Skule', '50010', 'HKraft->HardangerNett - Noderom inne på Røldal Skule', 59.83169568, 6.81389662),
('Odda11 Kleivavegen', 'Odda11', 'Opheimsgata 2 i Odda', 60.07010364, 6.54100078),
('Odda12 Eide', '50012', 'HKraft->HardangerNett - Nodehytta på Eide ved Hovden i Odda', 60.05451762, 6.54615561),
('Odda1 Nyland', '50001', 'HKraft->HardangerNett - Noderom i Odda, sentrum, Nyland', 60.06309682, 6.54730475),
('Odda2 Håra Røldal', '50002', 'HKraft->HardangerNett - Nodehytta i Håra ved Røldal skisenter i Odda.', 59.81899771, 6.74630863),
('Odda3 Langedalen', '50003', 'HKraft->HardangerNett - Noderom i hyttefeltet Langedalen i Odda', 59.90753782, 6.61309698),
('Odda4 Skare', '50004', 'HKraft->HardangerNett - Nodehytta Skare i Odda', 59.93370011, 6.59520247),
('Odda5 Tokheim', '50005', 'HKraft->HardangerNett - Nodehytta på Tokheim i Odda', 60.08699974, 6.52899487),
('Odda6 Tyssedal', '50006', 'HKraft->HardangerNett - Noderom i trafobygg i Tyssedal.', 60.12179687, 6.55690401),
('Odda7 OTK', '50007', 'HKraft->HardangerNett - Noderom på sida av trafoen OTK på smelteverkstomta i Odda', 60.06580386, 6.54980453),
('Odda8 Løyning', '50008', 'HKraft->HardangerNett - Nodehytta på Løyning i Odda', 59.90349883, 6.63349706),
('Odda9 Tømmerdalen', '50009', 'HKraft->HardangerNett - Nodehytta i Tømmerdalen i Odda', 59.89140174, 6.65229788),
('Ølen1', '30000', '', 59.59890572, 5.80934809),
('Osnes1', '10005', 'Haugaland - Node Osnes Torvastad - K3', 59.40530366, 5.23959839),
('Østremneset1', '10000', 'Haugaland - Østremneset, Kopervik - K3', 59.29078847, 5.30880165),
('Otterå1', '10900', '', 60.01688438, 5.20427805),
('Øvregaten1', '10044', 'Haugaland - Node i Øvregaten i Haugesund', 59.41889829, 5.26769684),
('Padlane1', '10009', 'Haugaland - Node Padlane Tysvær', 59.38138961, 5.47643879),
('Porshaug1', '10091', 'Haugaland - Nodehytte, Porshaug', 59.40070354, 5.30369977),
('Rekkje1', '10019', 'Nodehus Skutebergveien Vea', 59.29629909, 5.21729671),
('Ringen1', '10089', 'Haugaland - Node på Ringentunet', 59.40370353, 5.28680069),
('Risoy1', '10017', 'Haugaland - Node i Brokar risøy side', 59.40971012, 5.26673905),
('Rogalandsgt1', '10062', 'Haugaland - Nodehytte Rogalandsgata 57', 59.40863107, 5.28702625),
('Rossabø1', '10074', 'Haugaland - Node i HK kjeller', 59.39619809, 5.29379225),
('Røvær1', '10055', 'Haugaland - Node i skap på Røvær', 59.44009899, 5.08360497),
('Sæ1', '10201', '', 59.78522916, 5.49844524),
('Sand1', 'N-51', 'Haugaland - Node hos Suldal E. Verk', 59.48141401, 6.25182373),
('Sand2', 'N-50', 'Haugaland - Node i Prestaåsen', 59.48770279, 6.25709210),
('Sandeid1', '10090', 'Dobbelkiosk ved kyrkja', 59.54726962, 5.85863679),
('Sandve1', '10081', 'Node ved kiosk NS-11234', 59.17578592, 5.19242354),
('Sauda1', '10013', 'Haugaland - Node i Kiosk Sauda', 59.64710419, 6.34770167),
('Sauda2', '10025', 'Haugaland - Saudasjøen', 59.64020253, 6.30971536),
('Sauda3', '10075', 'Haugaland - Birkeland', 59.65180295, 6.37959791),
('Sauda4', '10118', 'Flogstad, Sauda', 59.65509894, 6.36229373),
('Sauda5', '10124', 'Svandalen, Sauda, Haugaland', 59.62839579, 6.26960192),
('Skånevik1', '30100', 'Haugaland - Node Mollhuset SØK', 59.73079758, 5.93369503),
('Skår1', '10079', 'Haugaland - Node Sundvegen, Skår', 59.28550201, 5.26369417),
('Skåre2', '10014', 'Haugaland - Noderom Skåredalen', 59.41100171, 5.32480135),
('Skåregata1', '10110', 'Noderom i Skåregata 101 losji hos BKK', 59.41150158, 5.27360046),
('Skipavåg', '10144', '', 59.48885124, 6.16711084),
('Skjeggedalsåsen', '50016', '', 60.12806772, 6.62899483),
('Skudenes1', '10059', 'Haugaland - Node i Skudenes Sekunderstasjon', 59.15400093, 5.24789513),
('Skudenes2', '10058', 'Haugaland - Node i Skudenes', 59.14840116, 5.27020475),
('Skudenes3', '10093', 'Haugaland - Node i dobbelkiosk på Øygardshaugen', 59.14939876, 5.24289722),
('Slattevik1', '10048', 'Nodehytte Slåttevik', 59.31459608, 5.46590373),
('Slettes - Leirvik1', '66558', 'Node 1 i SKL Leirvik', 59.78119978, 5.49700075),
('Smedasundet1', '10127', 'Nodehytte Smedasundet 2, Haugesund, Haugaland Kraft', 59.39750049, 5.28259894),
('Snurrevarden', '', 'Snurrevarden, Karmøy', 59.31519987, 5.28240140),
('Solvang1', '10015', 'Haugaland - Node i Solvang ved Haakonsvegen', 59.42375988, 5.29086831),
('Sørhaugaten1', '10108', 'Sørhauggaten 213, Frelsesarmeen', 59.41649579, 5.26569578),
('Spannadalen', '10120', 'Spannadalen, Haugaland', 59.38300269, 5.31390682),
('Stangeland1', '10022', 'Stangelandsfeltet Kopervik', 59.27469881, 5.31129684),
('Stangeland2', '10061', 'Haugaland - Node i Stangelandsstølen', 59.27870274, 5.30020025),
('Steinbru1', '30001', 'Steinbru, Ølen', 59.60549698, 5.82409791),
('steinsland1', '10130', 'Steinsland - ved nettstasjon 32064', 59.57411275, 5.80864994),
('Stemmemyr1', '10003', 'Haugaland - Node stemmemyr, spanne - K3', 59.38510446, 5.33014121),
('Storasund1', '10072', 'Haugaland - Node på Storasund', 59.38959848, 5.26880167),
('Storesundsgt1', '10050', 'Node i Dbl.kiosk Storesundsgaten', 59.40718288, 5.29881505),
('Strandgaten1', '10043', 'Haugaland - Node i strandgaten 162', 59.41360018, 5.26699604),
('Strandgaten2', '10039', 'Haugaland - Nodehytte i strandgaten', 59.40810049, 5.27359144),
('Straumen1', '10004', 'Haugaland - Node v kiosk i Skjoldastraumen - K3', 59.42539923, 5.62860660),
('Straumen2', '10133', 'Skjoldastraumen 2', 59.43030495, 5.61160748),
('Suldal1', 'N-60', 'Haugaland - Suldalsosen', 59.48938669, 6.49853307),
('Suldalseid', 'N-80', 'Haugaland - Suldalseid', 59.52680261, 6.39530809),
('Sveio1', '10049', 'Haugaland - Node i Lid Sekundærstasjon Sveio', 59.55269880, 5.40330184),
('Sveio2', '10067', 'Haugaland - Nodehytte i Sveio sentrum', 59.54489753, 5.35640521),
('Sveio3', '10063', 'Dobbelkiosk v/NS-71083 Sveio Kirkegård', 59.53969577, 5.33949267),
('Sveio Kommune', '', 'Sveio Kommune', 59.54459954, 5.36030248),
('Tjødnalio1', '63139', 'Nodehytte Tjødnalio', 59.77100139, 5.40559866),
('Tuastad1', '10096', 'Tuastad, ved Røyksund bru', 59.33539820, 5.36270474),
('Tysingvatnet1', 'N-21', 'Haugaland - Node i Tysingvatn stasjon', 59.39889865, 6.15389171),
('Udland1', '10052', 'Haugaland - Node på Udland', 59.44108770, 5.27257408),
('Utsira1', '10029', 'Haugaland- Node v kommunesenteret', 59.30529725, 4.88639959),
('Våg1', '10060', 'Haugaland - Våg 2.stasjon', 59.46530393, 5.46520060),
('Våga1', '10122', 'Våga, Sveio', 59.54290185, 5.44929629),
('Vågen1', '30003', 'Haugaland - Nodehytte SØK, bak Vågen Skole Ølen', 59.59581142, 5.74894811),
('Valevåg1', '10112', 'Node Valevåg, Haugaland Kraft', 59.69460311, 5.47639132),
('Veamyr1', '10007', 'Haugaland - Node på Veamyr - K3', 59.28360436, 5.23930311),
('Velde1', '10125', 'Nodehytte v/NS-31003 Velde, Vindafjord, Tysvær', 59.54860377, 5.70110558),
('Veldetun1', '10027', 'Haugaland - Node i kiosk på Veldetun', 59.34786168, 5.28236597),
('Vestlio1', '4486203', '', 59.80200024, 5.50649715),
('vestrevea1', '10138', '', 59.28799650, 5.22624297),
('Vetrhus1', '10140', '', 59.59248090, 6.34136195),
('Vihovda', '10102', 'Dobbelkiosk Vihovda, Sveio, Haugaland', 59.66669678, 5.45919756),
('Vikebygd1', '30005', 'Haugaland - Kiosk i vikebygd', 59.60110243, 5.59669412),
('Vikedal1', '10031', 'Node i Vikedal', 59.49589543, 5.90859748),
('Visnes1', '10085', 'Visnes, Karmøy', 59.35550932, 5.23448039),
('Vorrå1', '10053', 'Haugaland - Node på Snurrevarden', 59.31519987, 5.28240140),
('Yrkje1', '10137', 'Yrkje, Tysvaer', 59.39729798, 5.66550242),
('Ystad1', '10012', 'Node Ystadveien 1Ved SAS hotellet', 59.38439990, 5.30359579);

-- Forhåndsvisning: dette vil bli oppdatert
SELECT
    nl.id,
    nl.name,
    nl.lat AS old_lat,
    nl.lon AS old_lon,
    t.lat AS new_lat,
    t.lng AS new_lon
FROM `node_locations` nl
INNER JOIN `tmp_node_location_coords` t
    ON TRIM(LOWER(nl.name)) = TRIM(LOWER(t.nodelokasjon))
ORDER BY nl.name;

-- Viser rader i importlisten som IKKE finnes i node_locations
SELECT
    t.nodelokasjon,
    t.driftsmerking,
    t.beskrivelse,
    t.lat,
    t.lng
FROM `tmp_node_location_coords` t
LEFT JOIN `node_locations` nl
    ON TRIM(LOWER(nl.name)) = TRIM(LOWER(t.nodelokasjon))
WHERE nl.id IS NULL
ORDER BY t.nodelokasjon;

-- Oppdaterer bare eksisterende noder, oppretter ingen nye
UPDATE `node_locations` nl
INNER JOIN `tmp_node_location_coords` t
    ON TRIM(LOWER(nl.name)) = TRIM(LOWER(t.nodelokasjon))
SET
    nl.lat = t.lat,
    nl.lon = t.lng,
    nl.updated_by = 'csv-import',
    nl.updated_at = NOW()
WHERE
    (nl.lat IS NULL OR nl.lat <> t.lat)
    OR
    (nl.lon IS NULL OR nl.lon <> t.lng);

-- Resultat etter oppdatering
SELECT
    nl.id,
    nl.name,
    nl.lat,
    nl.lon,
    nl.updated_by,
    nl.updated_at
FROM `node_locations` nl
INNER JOIN `tmp_node_location_coords` t
    ON TRIM(LOWER(nl.name)) = TRIM(LOWER(t.nodelokasjon))
ORDER BY nl.name;

COMMIT;