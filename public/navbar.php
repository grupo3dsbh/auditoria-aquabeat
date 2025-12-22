<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url(); ?>">
            <i class="bi bi-graph-up-arrow"></i> Auditoria Aquabeat
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?php echo url('index.php'); ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="<?php echo url('relatorios.php'); ?>">
                        <i class="bi bi-file-earmark-bar-graph"></i> Relatórios
                    </a>
                </li>

                <li class="nav-item">
                    <a class="nav-link" href="<?php echo url('upload.php'); ?>">
                        <i class="bi bi-cloud-upload"></i> Importar CSV
                    </a>
                </li>

                <?php if (Auth::isAdmin() || Auth::hasRole('gerente')): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-gear"></i> Administração
                    </a>
                    <ul class="dropdown-menu">
                        <?php if (Auth::isAdmin()): ?>
                        <li><a class="dropdown-item" href="<?php echo url('admin/index.php'); ?>"><i class="bi bi-house"></i> Painel Admin</a></li>
                        <li><a class="dropdown-item" href="<?php echo url('admin/usuarios.php'); ?>"><i class="bi bi-people"></i> Usuários</a></li>
                        <li><a class="dropdown-item" href="<?php echo url('admin/configuracoes.php'); ?>"><i class="bi bi-sliders"></i> Configurações</a></li>
                        <li><a class="dropdown-item" href="<?php echo url('admin/logs.php'); ?>"><i class="bi bi-clock-history"></i> Logs</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo url('admin/queries.php'); ?>"><i class="bi bi-code-square"></i> Queries SQL</a></li>
                        <li><a class="dropdown-item" href="<?php echo url('admin/gerenciar_importacoes.php'); ?>"><i class="bi bi-trash"></i> Gerenciar Importações</a></li>
                        <li><a class="dropdown-item" href="<?php echo url('admin/recalcular_inadimplencia.php'); ?>"><i class="bi bi-calculator"></i> Recalcular Inadimplência</a></li>
                        <li><a class="dropdown-item" href="<?php echo url('admin/gerenciar_titulos_prefixo.php'); ?>"><i class="bi bi-filter-circle"></i> Gerenciar Prefixos (SFA/SBF)</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="<?php echo url('importacoes.php'); ?>"><i class="bi bi-list"></i> Histórico de Importações</a></li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>

            <ul class="navbar-nav">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle"></i> <?php echo sanitize(Auth::user()['nome']); ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?php echo url('perfil.php'); ?>"><i class="bi bi-person"></i> Meu Perfil</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?php echo url('logout.php'); ?>"><i class="bi bi-box-arrow-right"></i> Sair</a></li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<?php if (defined('SISTEMA_DEBUG_MODE') && SISTEMA_DEBUG_MODE === true): ?>
<div class="alert alert-warning mb-0 rounded-0 border-0" role="alert" style="border-bottom: 3px solid #ff9800 !important;">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-auto">
                <i class="bi bi-exclamation-triangle-fill" style="font-size: 1.5rem;"></i>
            </div>
            <div class="col">
                <strong>MODO DE DESENVOLVIMENTO ATIVADO</strong>
                <p class="mb-0 small">
                    Erros PHP estão sendo exibidos. Este modo deve ser desativado em produção.
                    <a href="<?php echo url('admin/configuracoes.php'); ?>" class="alert-link">Desativar nas Configurações</a>
                </p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
