<?php
// includes/componente_banner.php
// Parámetros esperados: $titulo, $subtitulo, $icono_bg, $botones (array), $widgets (array)
?>
<style>
    /* BANNER 100% REAL */
    .header-blue { width: 100vw; margin-left: calc(-50vw + 50%); background: <?php echo $color_sistema ?? '#102A57'; ?> !important; padding: 30px 0; position: relative; overflow: hidden; z-index: 10; border-radius: 0 !important; }
    
    /* ICONO TRANSPARENTE DINÁMICO */
    .bg-icon-large { position: absolute; right: -15px; top: 50%; transform: translateY(-50%) rotate(-10deg); font-size: 14rem; color: rgba(255,255,255,0.15); pointer-events: none; z-index: 0; }

    /* WIDGETS COMPACTOS Y SCROLL CELULAR (Idéntico a gastos.php) */
    @media (max-width: 768px) {
        .row-widgets { display: flex !important; overflow-x: auto; flex-wrap: nowrap; gap: 8px; padding: 0 15px 10px 15px; margin-left: -15px; margin-right: -15px; -webkit-overflow-scrolling: touch; }
        .row-widgets .col-banner { min-width: 155px; flex: 0 0 auto; }
        .header-widget { padding: 8px !important; margin-bottom: 0 !important; border-radius: 10px !important; }
        .widget-label { font-size: 0.55rem !important; margin-bottom: 2px !important; }
        .widget-value { font-size: 0.85rem !important; }
        .icon-box { width: 28px !important; height: 28px !important; font-size: 0.8rem !important; }
        
        .scroll-arrow { display: block !important; text-align: center; color: white; margin-top: 5px; font-size: 0.75rem; }
    }
    
    .scroll-arrow { display: none; }
    @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.2; } }
    .blink { animation: blink 1.2s infinite; }
</style>

<div class="header-blue">
    <i class="bi <?php echo $icono_bg; ?> bg-icon-large"></i>
    
    <div class="container position-relative" style="z-index: 2;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center mb-3 text-center text-md-start">
            <div class="mb-3 mb-md-0">
                <h2 class="font-cancha mb-0 text-white"><?php echo $titulo; ?></h2>
                <p class="opacity-75 mb-0 text-white small d-none d-md-block"><?php echo $subtitulo; ?></p>
            </div>
            
            <div class="d-flex gap-2 justify-content-center">
                <?php if(!empty($botones)): foreach($botones as $btn): ?>
                    <a href="<?php echo $btn['link']; ?>" 
                       target="<?php echo $btn['target'] ?? '_self'; ?>" 
                       class="<?php echo $btn['class']; ?>" 
                       style="font-size: 0.65rem; padding: 3px 10px; letter-spacing: 0.5px; min-width: 80px;">
                        
                        <?php if(!empty($btn['icono'])): ?>
                            <i class="bi <?php echo $btn['icono']; ?> me-1" style="font-size: 0.75rem;"></i>
                        <?php endif; ?>
                        
                        <?php echo $btn['texto']; ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div class="row row-widgets g-2">
            <?php if(!empty($widgets)): foreach($widgets as $w): ?>
                <div class="col-12 col-md-4 col-banner">
                    <div class="header-widget <?php echo $w['border'] ?? ''; ?>">
                        <div>
                            <div class="widget-label"><?php echo $w['label']; ?></div>
                            <div class="widget-value text-white"><?php echo $w['valor']; ?></div>
                        </div>
                        <div class="icon-box <?php echo $w['icon_bg'] ?? 'bg-white bg-opacity-10'; ?> text-white">
                            <i class="bi <?php echo $w['icono']; ?>"></i>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="scroll-arrow">
            DESLIZA <i class="bi bi-chevron-right blink"></i>
        </div>
    </div>
</div>