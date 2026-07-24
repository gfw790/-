import 'dart:math' as math;

import 'package:flutter/material.dart';

void main() {
  runApp(const TrayCutCalculatorApp());
}

class TrayCutCalculatorApp extends StatelessWidget {
  const TrayCutCalculatorApp({super.key});

  @override
  Widget build(BuildContext context) {
    const base = Color(0xFFFFF7F1);
    const accent = Color(0xFFC2410C);

    return MaterialApp(
      title: '트레이절단계산기',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        scaffoldBackgroundColor: base,
        colorScheme: ColorScheme.fromSeed(
          seedColor: accent,
          surface: base,
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: base,
          foregroundColor: Color(0xFF1C1917),
          elevation: 0,
          centerTitle: false,
        ),
        inputDecorationTheme: const InputDecorationTheme(
          border: OutlineInputBorder(),
        ),
      ),
      home: const HomeScreen(),
    );
  }
}

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> {
  int _index = 0;

  @override
  Widget build(BuildContext context) {
    final pages = <Widget>[
      const OneFoldScreen(),
      const TwoFoldScreen(),
    ];

    return Scaffold(
      appBar: AppBar(
        title: const Text(
          '트레이절단계산기',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
      ),
      body: SafeArea(child: pages[_index]),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _index,
        onDestinationSelected: (value) => setState(() => _index = value),
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.straighten),
            label: '1번 접기',
          ),
          NavigationDestination(
            icon: Icon(Icons.call_split),
            label: '2번 접기',
          ),
        ],
      ),
    );
  }
}

class OneFoldScreen extends StatefulWidget {
  const OneFoldScreen({super.key});

  @override
  State<OneFoldScreen> createState() => _OneFoldScreenState();
}

class _OneFoldScreenState extends State<OneFoldScreen> {
  static const trayWidths = [100, 150, 200, 300, 400, 450, 500, 600, 750];

  int? _trayWidth;
  double _angle = 0;

  int get _cutResult {
    if (_trayWidth == null) {
      return 0;
    }
    final radians = _degToRad(_angle.abs() / 2);
    return (_trayWidth! * math.tan(radians)).round();
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 32),
      children: [
        const Text(
          '1번 접기',
          style: TextStyle(
            fontSize: 28,
            fontWeight: FontWeight.w700,
            color: Color(0xFF1C1917),
          ),
        ),
        const SizedBox(height: 10),
        const Text(
          '트레이 폭을 먼저 선택한 뒤 각도를 조절하거나 숫자를 눌러 직접 입력하세요.',
          style: TextStyle(fontSize: 16, color: Color(0xFF57534E)),
        ),
        const SizedBox(height: 24),
        DropdownButtonFormField<int>(
          value: _trayWidth,
          decoration: const InputDecoration(
            labelText: '트레이 폭',
            helperText: '단위는 mm입니다.',
          ),
          items: trayWidths
              .map(
                (width) => DropdownMenuItem<int>(
                  value: width,
                  child: Text('$width'),
                ),
              )
              .toList(),
          onChanged: (value) => setState(() => _trayWidth = value),
        ),
        const SizedBox(height: 24),
        InkWell(
          borderRadius: BorderRadius.circular(18),
          onTap: _showAngleInputDialog,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 18),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(18),
              border: Border.all(color: const Color(0xFFE7D4C6)),
            ),
            child: Row(
              children: [
                const Expanded(
                  child: Text(
                    '현재 각도',
                    style: TextStyle(
                      fontSize: 16,
                      fontWeight: FontWeight.w600,
                      color: Color(0xFF7C2D12),
                    ),
                  ),
                ),
                Text(
                  '${_angle.round()}도',
                  style: const TextStyle(
                    fontSize: 28,
                    fontWeight: FontWeight.w700,
                    color: Color(0xFF9A3412),
                  ),
                ),
              ],
            ),
          ),
        ),
        const SizedBox(height: 20),
        Container(
          padding: const EdgeInsets.fromLTRB(16, 20, 16, 18),
          decoration: BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: const Color(0xFFE7D4C6)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                '각도 조절',
                style: TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.w700,
                  color: Color(0xFF7C2D12),
                ),
              ),
              const SizedBox(height: 8),
              Text(
                _trayWidth == null
                    ? '트레이를 선택하면 각도를 움직일 수 있습니다.'
                    : '-90도부터 90도까지 조절할 수 있습니다.',
                style: const TextStyle(
                  fontSize: 14,
                  color: Color(0xFF57534E),
                ),
              ),
              Slider(
                value: _angle,
                min: -90,
                max: 90,
                divisions: 180,
                label: '${_angle.round()}도',
                onChanged: _trayWidth == null
                    ? null
                    : (value) => setState(() => _angle = value),
              ),
            ],
          ),
        ),
        const SizedBox(height: 20),
        _ResultCard(
          title: '계산 결과',
          valueText: '중심선으로 부터 좌, 우 ${_cutResult}mm씩 절단하세요',
        ),
      ],
    );
  }

  Future<void> _showAngleInputDialog() async {
    if (_trayWidth == null) {
      _showMessage('트레이를 선택해주세요');
      return;
    }

    final controller = TextEditingController(text: _angle.round().toString());
    final entered = await showDialog<int>(
      context: context,
      builder: (context) {
        return AlertDialog(
          title: const Text('각도 직접 입력'),
          content: TextField(
            controller: controller,
            keyboardType: const TextInputType.numberWithOptions(
              signed: true,
              decimal: false,
            ),
            decoration: const InputDecoration(
              hintText: '예: -30 또는 45',
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('취소'),
            ),
            FilledButton(
              onPressed: () {
                Navigator.pop(context, int.tryParse(controller.text.trim()));
              },
              child: const Text('확인'),
            ),
          ],
        );
      },
    );

    if (entered != null) {
      setState(() {
        _angle = entered.clamp(-90, 90).toDouble();
      });
    }
  }

  void _showMessage(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text(message)),
    );
  }
}

class TwoFoldScreen extends StatefulWidget {
  const TwoFoldScreen({super.key});

  @override
  State<TwoFoldScreen> createState() => _TwoFoldScreenState();
}

class _TwoFoldScreenState extends State<TwoFoldScreen> {
  static const trayWidths = [100, 150, 200, 300, 400, 450, 500, 600, 750];

  final _angleController = TextEditingController();
  final _gapController = TextEditingController();

  int? _trayWidth;
  String? _gapError;

  @override
  void initState() {
    super.initState();
    _angleController.addListener(_refresh);
    _gapController.addListener(_refresh);
  }

  @override
  void dispose() {
    _angleController.dispose();
    _gapController.dispose();
    super.dispose();
  }

  double get _angle => (_readNumber(_angleController.text) ?? 0).clamp(0, 90);

  double? get _trayGap => _readNumber(_gapController.text);

  int get _cutPoint {
    if (_trayWidth == null) {
      return 0;
    }
    final radians = _degToRad(_angle / 2);
    return (_trayWidth! * math.tan(radians)).round();
  }

  int get _centerDistance {
    if (_trayWidth == null || _trayGap == null) {
      return 0;
    }
    final rise = _trayGap! - (_trayWidth! * 2);
    if (_angle <= 0 || rise <= 0) {
      return 0;
    }
    return (rise / math.sin(_degToRad(_angle))).round();
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.fromLTRB(24, 24, 24, 32),
      children: [
        const Text(
          '2번 접기',
          style: TextStyle(
            fontSize: 28,
            fontWeight: FontWeight.w700,
            color: Color(0xFF1C1917),
          ),
        ),
        const SizedBox(height: 10),
        const Text(
          '공통 접는 각도와 트레이 간격을 입력해 2번 접기 절단값을 계산합니다.',
          style: TextStyle(fontSize: 16, color: Color(0xFF57534E)),
        ),
        const SizedBox(height: 20),
        _GuideCard(onTap: _showGuideDialog),
        const SizedBox(height: 24),
        DropdownButtonFormField<int>(
          value: _trayWidth,
          decoration: const InputDecoration(
            labelText: '트레이 사이즈',
            helperText: '절단 계산에 사용할 트레이 사이즈를 선택합니다.',
          ),
          items: trayWidths
              .map(
                (width) => DropdownMenuItem<int>(
                  value: width,
                  child: Text('$width'),
                ),
              )
              .toList(),
          onChanged: (value) {
            setState(() {
              _trayWidth = value;
            });
            _refresh();
          },
        ),
        const SizedBox(height: 18),
        TextField(
          controller: _angleController,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: const InputDecoration(
            labelText: '공통 접는 각도',
          ),
        ),
        const SizedBox(height: 12),
        TextField(
          controller: _gapController,
          keyboardType: const TextInputType.numberWithOptions(decimal: true),
          decoration: InputDecoration(
            labelText: '트레이 간격',
            errorText: _gapError,
          ),
        ),
        const SizedBox(height: 20),
        _ResultCard(
          title: '최종 각도',
          valueText: '180도',
          body: [
            const SizedBox(height: 18),
            const Text(
              '절단점 1',
              style: _resultLabelStyle,
            ),
            const SizedBox(height: 6),
            Text(
              '절단점 1은 중심점에서 좌우로 ${_cutPoint}mm 잘라내야 합니다.',
              style: _resultValueStyle,
            ),
            const SizedBox(height: 18),
            const Text(
              '절단점 2',
              style: _resultLabelStyle,
            ),
            const SizedBox(height: 6),
            Text(
              '절단점 2는 중심점에서 좌우로 ${_cutPoint}mm 잘라내야 합니다.',
              style: _resultValueStyle,
            ),
            const SizedBox(height: 18),
            const Text(
              '중심점 간 거리',
              style: _resultLabelStyle,
            ),
            const SizedBox(height: 6),
            Text(
              _buildCenterDistanceMessage(),
              style: _resultValueStyle,
            ),
          ],
        ),
      ],
    );
  }

  void _refresh() {
    setState(() {
      if (_trayWidth != null && _trayGap != null && _trayGap! <= _trayWidth! * 2) {
        _gapError = '트레이 간격은 위아래 트레이 길이의 합보다 커야 합니다.';
      } else {
        _gapError = null;
      }
    });
  }

  String _buildCenterDistanceMessage() {
    if (_trayWidth == null || _trayGap == null || _gapError != null) {
      return '평행 180도 모드에서 계산됩니다.';
    }
    return '아랫변 기준 중심점 간의 거리는 ${_centerDistance}mm입니다.';
  }

  Future<void> _showGuideDialog() async {
    await showDialog<void>(
      context: context,
      builder: (context) {
        return Dialog.fullscreen(
          child: Scaffold(
            backgroundColor: Colors.black.withValues(alpha: 0.92),
            appBar: AppBar(
              backgroundColor: Colors.transparent,
              foregroundColor: Colors.white,
              title: const Text('작업 안내도 확대'),
            ),
            body: InteractiveViewer(
              minScale: 1,
              maxScale: 5,
              child: Center(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Image.asset('assets/images/two_fold_guide_reference.png'),
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _GuideCard extends StatelessWidget {
  const _GuideCard({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            '작업 안내도',
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.w700,
              color: Color(0xFF7C2D12),
            ),
          ),
          const SizedBox(height: 14),
          InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(14),
            child: ClipRRect(
              borderRadius: BorderRadius.circular(14),
              child: Stack(
                alignment: Alignment.bottomRight,
                children: [
                  Image.asset(
                    'assets/images/two_fold_guide_reference.png',
                    fit: BoxFit.fitWidth,
                  ),
                  Container(
                    margin: const EdgeInsets.all(12),
                    padding: const EdgeInsets.symmetric(
                      horizontal: 10,
                      vertical: 6,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.black.withValues(alpha: 0.72),
                      borderRadius: BorderRadius.circular(999),
                    ),
                    child: const Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Icon(Icons.zoom_in, size: 16, color: Colors.white),
                        SizedBox(width: 4),
                        Text(
                          '확대',
                          style: TextStyle(color: Colors.white),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ResultCard extends StatelessWidget {
  const _ResultCard({
    required this.title,
    required this.valueText,
    this.body = const [],
  });

  final String title;
  final String valueText;
  final List<Widget> body;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(
        color: const Color(0xFFFDE7D8),
        borderRadius: BorderRadius.circular(20),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(title, style: _resultLabelStyle),
          const SizedBox(height: 6),
          Text(valueText, style: _resultHeadlineStyle),
          ...body,
        ],
      ),
    );
  }
}

const _resultLabelStyle = TextStyle(
  fontSize: 14,
  fontWeight: FontWeight.w700,
  color: Color(0xFF7C2D12),
);

const _resultHeadlineStyle = TextStyle(
  fontSize: 30,
  fontWeight: FontWeight.w800,
  color: Color(0xFF9A3412),
);

const _resultValueStyle = TextStyle(
  fontSize: 24,
  fontWeight: FontWeight.w800,
  color: Color(0xFF9A3412),
);

double _degToRad(double degree) => degree * math.pi / 180;

double? _readNumber(String text) {
  final trimmed = text.trim();
  if (trimmed.isEmpty) {
    return null;
  }
  return double.tryParse(trimmed);
}
