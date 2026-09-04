import 'package:flutter/material.dart';

import '../api_client.dart';
import '../theme/app_theme.dart';
import 'event_summary_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key, required this.apiClient});

  final ApiClient apiClient;

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();

  bool _loading = false;
  bool _obscurePassword = true;
  String? _error;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;

    setState(() {
      _loading = true;
      _error = null;
    });

    try {
      await widget.apiClient.login(
        _emailController.text.trim(),
        _passwordController.text,
      );

      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => EventSummaryScreen(apiClient: widget.apiClient),
        ),
      );
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: BrandBackground(
        child: SafeArea(
          child: LayoutBuilder(
            builder: (context, constraints) {
              final horizontalPadding =
                  constraints.maxWidth >= 600 ? 48.0 : 24.0;

              return SingleChildScrollView(
                padding: EdgeInsets.fromLTRB(
                    horizontalPadding, 24, horizontalPadding, 28),
                child: ConstrainedBox(
                  constraints:
                      BoxConstraints(minHeight: constraints.maxHeight - 52),
                  child: Center(
                    child: ConstrainedBox(
                      constraints: const BoxConstraints(maxWidth: 460),
                      child: AutofillGroup(
                        child: Form(
                          key: _formKey,
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.stretch,
                            children: [
                              const Align(
                                alignment: Alignment.centerLeft,
                                child: BrandLogo(size: 68),
                              ),
                              const SizedBox(height: 34),
                              Text(
                                'O seu evento,\nsempre por perto.',
                                style: Theme.of(context)
                                    .textTheme
                                    .displaySmall
                                    ?.copyWith(
                                      height: 1.05,
                                      fontWeight: FontWeight.w700,
                                      letterSpacing: -1.3,
                                    ),
                              ),
                              const SizedBox(height: 14),
                              const Text(
                                'Acompanhe a faturação e o desempenho da operação em tempo real.',
                                style: TextStyle(
                                  color: AppColors.textMuted,
                                  fontSize: 16,
                                  height: 1.45,
                                ),
                              ),
                              const SizedBox(height: 36),
                              Container(
                                padding: const EdgeInsets.all(22),
                                decoration: BoxDecoration(
                                  color:
                                      AppColors.surface.withValues(alpha: 0.9),
                                  border: Border.all(color: AppColors.border),
                                  borderRadius: BorderRadius.circular(28),
                                  boxShadow: const [
                                    BoxShadow(
                                      color: Color(0x4D000000),
                                      blurRadius: 44,
                                      offset: Offset(0, 22),
                                    ),
                                  ],
                                ),
                                child: Column(
                                  crossAxisAlignment:
                                      CrossAxisAlignment.stretch,
                                  children: [
                                    const Text(
                                      'O MEU EVENTO',
                                      style: TextStyle(
                                        color: AppColors.textMuted,
                                        fontSize: 11,
                                        fontWeight: FontWeight.w700,
                                        letterSpacing: 2,
                                      ),
                                    ),
                                    const SizedBox(height: 8),
                                    const Text(
                                      'Bem-vindo de volta',
                                      style: TextStyle(
                                          fontSize: 22,
                                          fontWeight: FontWeight.w600),
                                    ),
                                    const SizedBox(height: 22),
                                    TextFormField(
                                      controller: _emailController,
                                      keyboardType: TextInputType.emailAddress,
                                      autofillHints: const [
                                        AutofillHints.username
                                      ],
                                      textInputAction: TextInputAction.next,
                                      decoration: const InputDecoration(
                                        labelText: 'Email',
                                        prefixIcon:
                                            Icon(Icons.alternate_email_rounded),
                                      ),
                                      validator: (value) =>
                                          (value == null || value.isEmpty)
                                              ? 'Introduza o seu email'
                                              : null,
                                    ),
                                    const SizedBox(height: 14),
                                    TextFormField(
                                      controller: _passwordController,
                                      obscureText: _obscurePassword,
                                      autofillHints: const [
                                        AutofillHints.password
                                      ],
                                      decoration: InputDecoration(
                                        labelText: 'Palavra-passe',
                                        prefixIcon: const Icon(
                                            Icons.lock_outline_rounded),
                                        suffixIcon: IconButton(
                                          tooltip: _obscurePassword
                                              ? 'Mostrar palavra-passe'
                                              : 'Ocultar palavra-passe',
                                          onPressed: () => setState(
                                            () => _obscurePassword =
                                                !_obscurePassword,
                                          ),
                                          icon: Icon(
                                            _obscurePassword
                                                ? Icons.visibility_outlined
                                                : Icons.visibility_off_outlined,
                                          ),
                                        ),
                                      ),
                                      validator: (value) =>
                                          (value == null || value.isEmpty)
                                              ? 'Introduza a sua palavra-passe'
                                              : null,
                                      onFieldSubmitted: (_) => _submit(),
                                    ),
                                    if (_error != null) ...[
                                      const SizedBox(height: 14),
                                      Container(
                                        padding: const EdgeInsets.all(12),
                                        decoration: BoxDecoration(
                                          color: Theme.of(context)
                                              .colorScheme
                                              .error
                                              .withValues(alpha: 0.1),
                                          borderRadius:
                                              BorderRadius.circular(12),
                                        ),
                                        child: Text(
                                          _error!,
                                          style: TextStyle(
                                            color: Theme.of(context)
                                                .colorScheme
                                                .error,
                                          ),
                                          textAlign: TextAlign.center,
                                        ),
                                      ),
                                    ],
                                    const SizedBox(height: 20),
                                    FilledButton(
                                      onPressed: _loading ? null : _submit,
                                      child: _loading
                                          ? const SizedBox(
                                              height: 22,
                                              width: 22,
                                              child: CircularProgressIndicator(
                                                color: AppColors.navy,
                                                strokeWidth: 2.4,
                                              ),
                                            )
                                          : const Row(
                                              mainAxisAlignment:
                                                  MainAxisAlignment.center,
                                              children: [
                                                Text('Entrar no evento'),
                                                SizedBox(width: 10),
                                                Icon(
                                                    Icons.arrow_forward_rounded,
                                                    size: 20),
                                              ],
                                            ),
                                    ),
                                  ],
                                ),
                              ),
                              const SizedBox(height: 22),
                              const Row(
                                mainAxisAlignment: MainAxisAlignment.center,
                                children: [
                                  Icon(Icons.shield_outlined,
                                      size: 16, color: AppColors.textMuted),
                                  SizedBox(width: 7),
                                  Text(
                                    'Acesso seguro Contacto Digital',
                                    style: TextStyle(
                                        color: AppColors.textMuted,
                                        fontSize: 12),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ),
                    ),
                  ),
                ),
              );
            },
          ),
        ),
      ),
    );
  }
}
