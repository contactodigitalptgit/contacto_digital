import 'package:flutter/material.dart';

abstract final class AppColors {
  static const navy = Color(0xFF03182B);
  static const navyDeep = Color(0xFF01101F);
  static const surface = Color(0xFF061F36);
  static const surfaceRaised = Color(0xFF082742);
  static const blue = Color(0xFF00419B);
  static const blueBright = Color(0xFF2468D8);
  static const lime = Color(0xFFE7FF49);
  static const white = Color(0xFFEDEDED);
  static const textSoft = Color(0xFFC6D1DC);
  static const textMuted = Color(0xFF8EA3B8);
  static const border = Color(0x1AEDEDED);
  static const success = Color(0xFF75E6C1);
  static const warning = Color(0xFFFFB648);
}

abstract final class AppTheme {
  static ThemeData get dark {
    final base = ThemeData.dark(useMaterial3: true);
    final colorScheme = ColorScheme.fromSeed(
      seedColor: AppColors.blue,
      brightness: Brightness.dark,
      primary: AppColors.lime,
      secondary: AppColors.blueBright,
      surface: AppColors.surface,
    );

    return base.copyWith(
      colorScheme: colorScheme,
      scaffoldBackgroundColor: AppColors.navy,
      textTheme: base.textTheme.apply(
        bodyColor: AppColors.white,
        displayColor: AppColors.white,
        fontFamily: 'Avenir Next',
      ),
      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: AppColors.surfaceRaised,
        labelStyle: const TextStyle(color: AppColors.textMuted),
        hintStyle: const TextStyle(color: AppColors.textMuted),
        prefixIconColor: AppColors.textMuted,
        contentPadding:
            const EdgeInsets.symmetric(horizontal: 18, vertical: 18),
        border: _inputBorder(AppColors.border),
        enabledBorder: _inputBorder(AppColors.border),
        focusedBorder: _inputBorder(AppColors.lime, width: 1.4),
        errorBorder: _inputBorder(colorScheme.error),
        focusedErrorBorder: _inputBorder(colorScheme.error, width: 1.4),
      ),
      filledButtonTheme: FilledButtonThemeData(
        style: FilledButton.styleFrom(
          backgroundColor: AppColors.lime,
          foregroundColor: AppColors.navy,
          disabledBackgroundColor: AppColors.lime.withValues(alpha: 0.35),
          minimumSize: const Size.fromHeight(56),
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
          textStyle: const TextStyle(fontSize: 16, fontWeight: FontWeight.w700),
        ),
      ),
      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.lime,
        linearTrackColor: AppColors.surfaceRaised,
      ),
      dividerTheme:
          const DividerThemeData(color: AppColors.border, thickness: 1),
    );
  }

  static OutlineInputBorder _inputBorder(Color color, {double width = 1}) {
    return OutlineInputBorder(
      borderRadius: BorderRadius.circular(18),
      borderSide: BorderSide(color: color, width: width),
    );
  }
}

class BrandLogo extends StatelessWidget {
  const BrandLogo({super.key, this.size = 64});

  final double size;

  @override
  Widget build(BuildContext context) {
    return Image.asset(
      'assets/images/logo-contacto-digital.webp',
      width: size,
      height: size,
      fit: BoxFit.contain,
      filterQuality: FilterQuality.high,
    );
  }
}

class BrandBackground extends StatelessWidget {
  const BrandBackground({super.key, required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [AppColors.navyDeep, AppColors.navy, Color(0xFF04213A)],
          stops: [0, 0.46, 1],
        ),
      ),
      child: Stack(
        children: [
          const Positioned(
            top: -150,
            right: -100,
            child: _GlowOrb(color: AppColors.blue, size: 340),
          ),
          const Positioned(
            bottom: -190,
            left: -160,
            child: _GlowOrb(color: Color(0xFF3B6B62), size: 380),
          ),
          Positioned.fill(child: child),
        ],
      ),
    );
  }
}

class _GlowOrb extends StatelessWidget {
  const _GlowOrb({required this.color, required this.size});

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return IgnorePointer(
      child: Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(
            colors: [color.withValues(alpha: 0.24), color.withValues(alpha: 0)],
          ),
        ),
      ),
    );
  }
}
