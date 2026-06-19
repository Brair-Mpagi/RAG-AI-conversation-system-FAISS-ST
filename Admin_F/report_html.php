<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Report – MMU Chatbot</title>
<link rel="shortcut icon" href="images/mmu_logo_- no bg.png" type="image/x-icon">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
<link href="css/style.css?v=1775081173" rel="stylesheet">
<link href="css/style-mob.css" rel="stylesheet">
<link href="css/admin.css" rel="stylesheet">
<link href="css/admin-profile.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
*{font-family:'Inter','Open Sans',sans-serif;}
[class*="fa-"]{font-family:"Font Awesome 6 Free"!important;font-weight:900!important;font-style:normal!important;}
.rpt-wrap{max-width:1100px;margin:0 auto;padding:0 8px 40px;}
.rpt-header{text-align:center;padding:28px 20px 18px;border-bottom:2px solid #e2e8f0;margin-bottom:24px;}
.rpt-header .logo{max-width:110px;margin-bottom:10px;}
.rpt-uni{font-size:1.55rem;font-weight:800;color:#002147;letter-spacing:.3px;}
.rpt-sub{font-size:1.1rem;font-weight:600;color:#1746a2;margin:2px 0;}
.rpt-type{font-size:.95rem;color:#64748b;margin:4px 0 12px;}
.rpt-meta{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin:8px 0 6px;}
.rpt-meta span{background:#f1f5f9;border-radius:6px;padding:4px 14px;font-size:.82rem;color:#334155;font-weight:500;}
.rpt-badge{display:inline-block;background:#dbeafe;color:#1e40af;border-radius:20px;padding:3px 16px;font-size:.85rem;font-weight:600;margin-top:4px;}
.btn-export-pdf{background:#002147;color:#fff!important;font-weight:600;border:none;border-radius:8px;padding:10px 26px;font-size:.95rem;text-decoration:none;transition:background .18s;display:inline-flex;align-items:center;gap:8px;}
.btn-export-pdf:hover{background:#1746a2;color:#fff;}
.rpt-filter{background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;padding:16px 20px 8px;margin-bottom:24px;}
.rpt-filter-row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;}
.rpt-filter-col{flex:1 1 180px;min-width:160px;margin-bottom:8px;}
.rpt-filter-col label{font-weight:600;font-size:.82rem;color:#475569;display:block;margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px;}
.rpt-filter-col select,.rpt-filter-col input[type=date]{border:1px solid #cbd5e1;border-radius:6px;padding:7px 10px;width:100%;font-size:.9rem;background:#fff;}
.rpt-filter-checkboxes{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px;}
.rpt-filter-checkboxes label{font-size:.82rem;display:flex;align-items:center;gap:5px;background:#fff;border:1px solid #e2e8f0;padding:4px 10px;border-radius:6px;cursor:pointer;}
.apply-btn{background:#002147;color:#fff;border:none;border-radius:7px;padding:9px 20px;font-weight:600;font-size:.9rem;cursor:pointer;}
.apply-btn:hover{background:#1746a2;}
.section{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;margin-bottom:18px;box-shadow:0 1px 4px rgba(0,0,0,.05);}
.st{font-size:1rem;font-weight:700;color:#002147;margin-bottom:14px;display:flex;align-items:center;gap:8px;padding-bottom:10px;border-bottom:1px solid #f1f5f9;}
.st i{color:#3b82f6;}
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(155px,1fr));gap:12px;margin-bottom:18px;}
.kc{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:15px 12px;text-align:center;border-top:3px solid #3b82f6;transition:transform .2s,box-shadow .2s;}
.kc:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.08);}
.kl{font-size:.7rem;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.kv{font-size:1.4rem;font-weight:800;color:#002147;}
.ks{font-size:.72rem;font-weight:600;margin-top:2px;}
.up{color:#10b981!important;}.dn{color:#ef4444!important;}.ne{color:#64748b!important;}
.g{border-top-color:#10b981!important;}.a{border-top-color:#f59e0b!important;}.r{border-top-color:#ef4444!important;}.p{border-top-color:#8b5cf6!important;}.c{border-top-color:#06b6d4!important;}
.rt{width:100%;border-collapse:collapse;}
.rt thead th{background:#f8fafc;color:#64748b;font-size:.75rem;text-transform:uppercase;letter-spacing:.4px;padding:9px 12px;border-bottom:2px solid #e2e8f0;}
.rt tbody td{padding:9px 12px;color:#334155;font-size:.84rem;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.rt tbody tr:hover td{background:rgba(59,130,246,.03);}
.bs{display:inline-block;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:600;}
.bsa{background:#dcfce7;color:#166534;}.bsi{background:#f1f5f9;color:#64748b;}.bst{background:#fef3c7;color:#92400e;}.bsd{background:#fee2e2;color:#991b1b;}
.pbw{background:#f1f5f9;border-radius:6px;height:16px;overflow:hidden;margin-bottom:6px;}
.pbf{height:100%;border-radius:6px;display:flex;align-items:center;justify-content:flex-end;padding-right:6px;font-size:.66rem;color:#fff;font-weight:700;min-width:20px;}
.fg{background:linear-gradient(90deg,#10b981,#34d399);}.fa2{background:linear-gradient(90deg,#f59e0b,#fbbf24);}.fr{background:linear-gradient(90deg,#ef4444,#f87171);}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
@media(max-width:700px){.two-col{grid-template-columns:1fr;}.kpi-grid{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<?php include 'includes/topbar.php'; ?>
</div></div>
<div class="container-fluid sb2"><div class="row">
<?php include 'includes/sidebar.php'; ?>
<div class="sb2-2 col-md-9">

<div class="sb2-2-3"><div class="rpt-wrap">

<!-- HEADER -->
<div class="rpt-header">
  <div><img src="images/mmu_logo_- no bg.png" alt="MMU Logo" class="logo"></div>
  <div class="rpt-uni">MOUNTAINS OF THE MOON UNIVERSITY</div>
  <div class="rpt-sub">Campus Query Chatbot System</div>
  <div class="rpt-type">Enterprise Performance Report</div>
  <div class="rpt-meta">
    <span><i class="fa-solid fa-user" style="color:#3b82f6;margin-right:4px;"></i>Generated by: <b><?php echo htmlspecialchars($admin['username']??'Admin');?></b></span>
    <span><i class="fa-solid fa-id-badge" style="color:#8b5cf6;margin-right:4px;"></i>ID: <b><?php echo htmlspecialchars(format_admin_id($admin['admin_id']??null));?></b></span>
    <span><i class="fa-solid fa-calendar" style="color:#10b981;margin-right:4px;"></i><?php echo date('F j, Y \a\t H:i');?></span>
  </div>
  <span class="rpt-badge"><i class="fa-solid fa-clock" style="margin-right:5px;"></i><?php echo htmlspecialchars($dl);?></span>
</div>

<!-- Export button -->
<div style="display:flex;justify-content:flex-end;margin-bottom:16px;">
  <form id="pdfForm" method="POST" action="report.php" style="display:inline;">
    <?php foreach($_GET as $k=>$v){if(is_array($v)){foreach($v as $val)echo "<input type='hidden' name='".htmlspecialchars($k)."[]' value='".htmlspecialchars($val)."'>";}else echo "<input type='hidden' name='".htmlspecialchars($k)."' value='".htmlspecialchars($v)."'>";}?>
    <input type="hidden" name="export" value="pdf">
    <button type="submit" class="btn-export-pdf"><i class="fa-solid fa-file-pdf"></i> Export to PDF</button>
  </form>
</div>

<!-- FILTERS -->
<div class="rpt-filter">
  <?php if($dr==='custom'&&(empty($from)||empty($to))):?><div style="color:#ef4444;font-weight:600;margin-bottom:8px;">Please select both From and To dates.</div><?php endif;?>
  <form method="GET" style="margin:0;">
    <div class="rpt-filter-row">
      <div class="rpt-filter-col"><label>Date Range</label>
        <select name="date_range" onchange="document.getElementById('cdates').style.display=this.value==='custom'?'flex':'none'">
          <option value="7"<?php echo $dr==='7'?' selected':'';?>>Last 7 Days</option>
          <option value="30"<?php echo $dr==='30'?' selected':'';?>>Last 30 Days</option>
          <option value="60"<?php echo $dr==='60'?' selected':'';?>>Last 60 Days</option>
          <option value="custom"<?php echo $dr==='custom'?' selected':'';?>>Custom Range</option>
        </select>
      </div>
      <div class="rpt-filter-col" id="cdates" style="display:<?php echo $dr==='custom'?'flex':'none';?>;gap:8px;align-items:flex-end;">
        <div style="flex:1;"><label>From</label><input type="date" name="from" value="<?php echo htmlspecialchars($from);?>"></div>
        <div style="flex:1;"><label>To</label><input type="date" name="to" value="<?php echo htmlspecialchars($to);?>"></div>
      </div>
      <div class="rpt-filter-col"><label>Sections to Include</label>
        <div class="rpt-filter-checkboxes">
          <?php
          $sects=['overview'=>'Overview','kb'=>'Knowledge Base','ai'=>'AI Models','scraping'=>'Web Scraping','inquiries'=>'Inquiries','feedback'=>'Feedback','intents'=>'Intent Analysis','faq'=>'Top Questions','graphs'=>'Graphs','health'=>'System Health'];
          $def_sects=array_keys($sects);
          $sel_sects=isset($_GET['sections'])&&is_array($_GET['sections'])&&!empty($_GET['sections'])?array_filter($_GET['sections'],fn($s)=>array_key_exists($s,$sects)):$def_sects;
          foreach($sects as $k=>$l){$chk=in_array($k,$sel_sects)?'checked':'';echo "<label><input type='checkbox' name='sections[]' value='".htmlspecialchars($k)."' $chk> ".htmlspecialchars($l)."</label>";}
          ?>
        </div>
      </div>
      <div class="rpt-filter-col" style="flex:0 0 auto;align-self:flex-end;">
        <button type="submit" class="apply-btn"><i class="fa-solid fa-filter" style="margin-right:5px;"></i>Apply</button>
      </div>
    </div>
  </form>
</div>

<?php if(in_array('overview',$sel_sects)):?>
<!-- ── SECTION 1: KPIs ── -->
<div class="section" id="s-overview">
  <div class="st"><i class="fa-solid fa-chart-pie"></i> Chatbot Usage Overview</div>
  <div class="kpi-grid">
    <div class="kc"><div class="kl">Total Messages</div><div class="kv"><?php echo number_format($total_queries);?></div><div class="ks <?php echo $qs_change>=0?'up':'dn';?>"><?php echo($qs_change>=0?'↑+':'↓').$qs_change;?>% vs prev</div></div>
    <div class="kc g"><div class="kl">Unique Sessions</div><div class="kv"><?php echo number_format($total_sessions);?></div><div class="ks <?php echo $ss_change>=0?'up':'dn';?>"><?php echo($ss_change>=0?'↑+':'↓').$ss_change;?>% vs prev</div></div>
    <div class="kc a"><div class="kl">Avg Queries/Session</div><div class="kv"><?php echo $avg_per_session;?></div><div class="ks ne">messages</div></div>
    <div class="kc c"><div class="kl">Avg Response Time</div><div class="kv"><?php echo round($avg_resp);?><span style="font-size:.7rem;">ms</span></div></div>
    <div class="kc p"><div class="kl">Avg Confidence</div><div class="kv"><?php echo round($avg_conf*100,1);?>%</div></div>
    <div class="kc"><div class="kl">RAG Responses</div><div class="kv"><?php echo number_format($ctx_used);?></div><div class="ks ne">context retrieved</div></div>
    <div class="kc a"><div class="kl">Peak Day Queries</div><div class="kv"><?php echo number_format($peak_day);?></div></div>
    <div class="kc g"><div class="kl">Conversations</div><div class="kv"><?php echo number_format($conv_total);?></div><div class="ks ne"><?php echo number_format($conv_completed);?> completed</div></div>
    <div class="kc c"><div class="kl">Avg Session Duration</div><div class="kv"><?php echo round($avg_sess_dur);?><span style="font-size:.7rem;">s</span></div></div>
  </div>

  <?php if(!empty($device_breakdown)):?>
  <div class="st" style="margin-top:10px;"><i class="fa-solid fa-mobile-screen"></i> Session Device Breakdown</div>
  <table class="rt">
    <thead><tr><th>Device Type</th><th>Sessions</th><th>Share</th></tr></thead>
    <tbody>
    <?php $tot_dev=array_sum(array_column($device_breakdown,'cnt'));
    foreach($device_breakdown as $d):?>
    <tr><td><?php echo htmlspecialchars(ucfirst($d['dt']));?></td><td><?php echo number_format($d['cnt']);?></td><td><?php echo $tot_dev?round($d['cnt']/$tot_dev*100,1):0;?>%</td></tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>

  <?php if(!empty($resp_types)):?>
  <div class="st" style="margin-top:10px;"><i class="fa-solid fa-layer-group"></i> Response Type Breakdown</div>
  <?php $rt_total=array_sum(array_column($resp_types,'cnt'));?>
  <table class="rt">
    <thead><tr><th>Response Type</th><th>Count</th><th>%</th><th>Share</th></tr></thead>
    <tbody>
    <?php foreach($resp_types as $rt):$pct=$rt_total?round($rt['cnt']/$rt_total*100,1):0;?>
    <tr>
      <td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$rt['rt'])));?></td>
      <td><?php echo number_format($rt['cnt']);?></td>
      <td><?php echo $pct;?>%</td>
      <td style="min-width:120px;"><div class="pbw"><div class="pbf fg" style="width:<?php echo $pct;?>%"><?php echo $pct;?>%</div></div></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>

  <?php if(!empty($models_used)):?>
  <div class="st" style="margin-top:10px;"><i class="fa-solid fa-microchip"></i> Model Usage in Period</div>
  <table class="rt">
    <thead><tr><th>Model</th><th>Messages Handled</th></tr></thead>
    <tbody><?php foreach($models_used as $m):?><tr><td><?php echo htmlspecialchars($m['m']);?></td><td><?php echo number_format($m['cnt']);?></td></tr><?php endforeach;?></tbody>
  </table>
  <?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('kb',$sel_sects)):?>
<!-- ── SECTION 2: KNOWLEDGE BASE ── -->
<div class="section" id="s-kb">
  <div class="st"><i class="fa-solid fa-database"></i> Knowledge Base Health</div>
  <div class="kpi-grid">
    <div class="kc g"><div class="kl">Active Entities</div><div class="kv"><?php echo number_format($kb_entities);?></div></div>
    <div class="kc p"><div class="kl">Knowledge Chunks</div><div class="kv"><?php echo number_format($kb_chunks);?></div></div>
    <div class="kc a"><div class="kl">KB Items</div><div class="kv"><?php echo number_format($kb_items);?></div></div>
  </div>

  <?php if(!empty($kb_entity_types)):?>
  <div class="two-col">
    <div>
      <p style="font-weight:600;font-size:.83rem;color:#475569;margin-bottom:8px;">ENTITY TYPE BREAKDOWN</p>
      <table class="rt">
        <thead><tr><th>Type</th><th>Entities</th></tr></thead>
        <tbody><?php foreach($kb_entity_types as $et):?><tr><td><?php echo htmlspecialchars($et['type_label']);?></td><td><?php echo number_format($et['cnt']);?></td></tr><?php endforeach;?></tbody>
      </table>
    </div>
    <div>
      <p style="font-weight:600;font-size:.83rem;color:#475569;margin-bottom:8px;">KNOWLEDGE ITEMS BY CATEGORY</p>
      <table class="rt">
        <thead><tr><th>Category</th><th>Items</th></tr></thead>
        <tbody><?php foreach($kb_items_by_cat as $c):?><tr><td><?php echo htmlspecialchars($c['category']);?></td><td><?php echo number_format($c['cnt']);?></td></tr><?php endforeach;?></tbody>
      </table>
    </div>
  </div>
  <?php endif;?>

  <?php if(!empty($kb_recent_changes)):?>
  <p style="font-weight:600;font-size:.83rem;color:#475569;margin-top:10px;margin-bottom:6px;">ENTITY CHANGES (LAST 30 DAYS)</p>
  <table class="rt">
    <thead><tr><th>Action</th><th>Count</th></tr></thead>
    <tbody><?php foreach($kb_recent_changes as $ch):?><tr><td><?php echo htmlspecialchars(ucfirst($ch['action']));?></td><td><?php echo number_format($ch['cnt']);?></td></tr><?php endforeach;?></tbody>
  </table>
  <?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('ai',$sel_sects)):?>
<!-- ── SECTION 3: AI MODELS ── -->
<div class="section" id="s-ai">
  <div class="st"><i class="fa-solid fa-robot"></i> AI Model Registry</div>
  <table class="rt">
    <thead><tr><th>Model Name</th><th>Type</th><th>Version</th><th>Status</th><th>Usage Count</th><th>Default</th></tr></thead>
    <tbody>
    <?php foreach($ai_models as $m):
      $s=htmlspecialchars($m['status']);
      $scls=['active'=>'bsa','inactive'=>'bsi','testing'=>'bst','deprecated'=>'bsd'][$m['status']]??'bsi';
    ?>
    <tr>
      <td><b><?php echo htmlspecialchars($m['model_name']);?></b></td>
      <td><?php echo htmlspecialchars(str_replace('_',' ',$m['model_type']));?></td>
      <td><?php echo htmlspecialchars($m['model_version']??'–');?></td>
      <td><span class="bs <?php echo $scls;?>"><?php echo $s;?></span></td>
      <td><?php echo number_format($m['usage_count']);?></td>
      <td><?php echo $m['is_default']?'<span class="bs bsa">Yes</span>':'<span class="bs bsi">No</span>';?></td>
    </tr>
    <?php endforeach;?>
    </tbody>
  </table>
  <?php if(!empty($ai_perf)):?>
  <p style="font-weight:600;font-size:.83rem;color:#475569;margin-top:14px;margin-bottom:6px;">MODEL PERFORMANCE METRICS (PERIOD)</p>
  <table class="rt">
    <thead><tr><th>Model</th><th>Requests</th><th>Avg Resp (ms)</th><th>Avg Similarity</th><th>Success</th><th>Failed</th></tr></thead>
    <tbody><?php foreach($ai_perf as $p):?>
    <tr><td><?php echo htmlspecialchars($p['model_name']);?></td><td><?php echo number_format($p['reqs']);?></td><td><?php echo round($p['avg_rt']);?></td><td><?php echo round($p['avg_sim']*100,1);?>%</td><td><?php echo number_format($p['succ']);?></td><td><?php echo number_format($p['fail']);?></td></tr>
    <?php endforeach;?></tbody>
  </table>
  <?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('scraping',$sel_sects)):?>
<!-- ── SECTION 4: WEB SCRAPING ── -->
<div class="section" id="s-scraping">
  <div class="st"><i class="fa-solid fa-spider"></i> Web Scraper Overview</div>
  <div class="kpi-grid">
    <div class="kc"><div class="kl">Total Scraped Pages</div><div class="kv"><?php echo number_format($scrape_total);?></div></div>
    <div class="kc g"><div class="kl">Indexed Pages</div><div class="kv"><?php echo number_format($scrape_indexed);?></div></div>
    <div class="kc a"><div class="kl">New This Period</div><div class="kv"><?php echo number_format($scrape_new);?></div></div>
    <div class="kc c"><div class="kl">Active Sources</div><div class="kv"><?php echo count(array_filter($scrape_sources,fn($s)=>$s['is_active']));?></div></div>
  </div>

  <?php if(!empty($scrape_by_status)):?>
  <div class="two-col">
    <div>
      <p style="font-weight:600;font-size:.83rem;color:#475569;margin-bottom:8px;">STATUS BREAKDOWN</p>
      <?php $st_total=array_sum(array_column($scrape_by_status,'cnt'));?>
      <table class="rt">
        <thead><tr><th>Status</th><th>Count</th><th>%</th></tr></thead>
        <tbody><?php foreach($scrape_by_status as $sb):?><tr><td><?php echo htmlspecialchars(ucfirst($sb['status']));?></td><td><?php echo number_format($sb['cnt']);?></td><td><?php echo $st_total?round($sb['cnt']/$st_total*100,1):0;?>%</td></tr><?php endforeach;?></tbody>
      </table>
    </div>
    <div>
      <p style="font-weight:600;font-size:.83rem;color:#475569;margin-bottom:8px;">SCRAPING SOURCES</p>
      <table class="rt">
        <thead><tr><th>Source</th><th>Status</th><th>Success</th><th>Fail</th></tr></thead>
        <tbody><?php foreach($scrape_sources as $src):?><tr><td><?php echo htmlspecialchars(mb_strimwidth($src['source_name'],0,30,'…'));?></td><td><span class="bs <?php echo $src['is_active']?'bsa':'bsi';?>"><?php echo $src['is_active']?'Active':'Inactive';?></span></td><td><?php echo number_format($src['success_count']);?></td><td><?php echo number_format($src['failure_count']);?></td></tr><?php endforeach;?></tbody>
      </table>
    </div>
  </div>
  <?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('inquiries',$sel_sects)):?>
<!-- ── SECTION 5: USER INQUIRIES ── -->
<div class="section" id="s-inquiries">
  <div class="st"><i class="fa-solid fa-envelope-open-text"></i> User Inquiries & Support Tickets</div>
  <div class="kpi-grid">
    <div class="kc"><div class="kl">Total Inquiries</div><div class="kv"><?php echo number_format($uq_total);?></div></div>
    <div class="kc r"><div class="kl">Pending</div><div class="kv"><?php echo number_format($uq_pending);?></div></div>
    <div class="kc a"><div class="kl">In Progress</div><div class="kv"><?php echo number_format($uq_inprog);?></div></div>
    <div class="kc g"><div class="kl">Resolved</div><div class="kv"><?php echo number_format($uq_resolved);?></div></div>
    <div class="kc r"><div class="kl">Urgent</div><div class="kv"><?php echo number_format($uq_urgent);?></div></div>
    <div class="kc c"><div class="kl">Avg Resolution</div><div class="kv"><?php echo round($uq_avg_res,1);?><span style="font-size:.7rem;">hrs</span></div></div>
  </div>
  <?php if(!empty($uq_by_type)):?>
  <p style="font-weight:600;font-size:.83rem;color:#475569;margin-bottom:6px;">INQUIRIES BY TYPE</p>
  <table class="rt">
    <thead><tr><th>Query Type</th><th>Count</th><th>Share</th></tr></thead>
    <tbody><?php foreach($uq_by_type as $t):$pct=$uq_total?round($t['cnt']/$uq_total*100,1):0;?><tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$t['query_type'])));?></td><td><?php echo number_format($t['cnt']);?></td><td><?php echo $pct;?>%</td></tr><?php endforeach;?></tbody>
  </table>
  <?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('feedback',$sel_sects)):?>
<!-- ── SECTION 6: FEEDBACK ── -->
<div class="section" id="s-feedback">
  <div class="st"><i class="fa-solid fa-face-smile"></i> User Feedback & Satisfaction</div>
  <div class="kpi-grid">
    <div class="kc g"><div class="kl">Excellent Ratings</div><div class="kv"><?php echo number_format($fb_excellent);?></div><div class="ks up"><?php echo $fb_total?round($fb_excellent/$fb_total*100,1):0;?>%</div></div>
    <div class="kc a"><div class="kl">Good Ratings</div><div class="kv"><?php echo number_format($fb_good);?></div><div class="ks ne"><?php echo $fb_total?round($fb_good/$fb_total*100,1):0;?>%</div></div>
    <div class="kc r"><div class="kl">Bad Ratings</div><div class="kv"><?php echo number_format($fb_bad);?></div><div class="ks dn"><?php echo $fb_total?round($fb_bad/$fb_total*100,1):0;?>%</div></div>
    <div class="kc"><div class="kl">Total Feedback</div><div class="kv"><?php echo number_format($fb_total);?></div></div>
    <div class="kc c"><div class="kl">Reviews Actioned</div><div class="kv"><?php echo number_format($fb_reviewed);?></div></div>
    <div class="kc <?php echo $fb_change>=0?'g':'r';?>"><div class="kl">Excellence Rate</div><div class="kv"><?php echo $fb_rate;?>%</div><div class="ks <?php echo $fb_change>=0?'up':'dn';?>"><?php echo($fb_change>=0?'+':'').$fb_change;?>% vs prev</div></div>
  </div>

  <?php $fb_max=max($fb_excellent,$fb_good,$fb_bad,1);?>
  <div style="display:flex;flex-direction:column;gap:6px;">
    <div style="font-size:.75rem;color:#64748b;font-weight:600;margin-bottom:2px;">RATING DISTRIBUTION</div>
    <div><span style="font-size:.8rem;color:#334155;display:inline-block;width:80px;">Excellent</span><div class="pbw" style="display:inline-block;width:calc(100% - 130px);vertical-align:middle;"><div class="pbf fg" style="width:<?php echo round($fb_excellent/$fb_max*100);?>%"><?php echo $fb_excellent;?></div></div></div>
    <div><span style="font-size:.8rem;color:#334155;display:inline-block;width:80px;">Good</span><div class="pbw" style="display:inline-block;width:calc(100% - 130px);vertical-align:middle;"><div class="pbf fa2" style="width:<?php echo round($fb_good/$fb_max*100);?>%"><?php echo $fb_good;?></div></div></div>
    <div><span style="font-size:.8rem;color:#334155;display:inline-block;width:80px;">Bad</span><div class="pbw" style="display:inline-block;width:calc(100% - 130px);vertical-align:middle;"><div class="pbf fr" style="width:<?php echo round($fb_bad/$fb_max*100);?>%"><?php echo $fb_bad;?></div></div></div>
  </div>

  <?php if(!empty($fb_by_cat)):?>
  <p style="font-weight:600;font-size:.83rem;color:#475569;margin-top:14px;margin-bottom:6px;">FEEDBACK BY CATEGORY</p>
  <table class="rt">
    <thead><tr><th>Category</th><th>Count</th></tr></thead>
    <tbody><?php foreach($fb_by_cat as $c):?><tr><td><?php echo htmlspecialchars(ucfirst($c['category']));?></td><td><?php echo number_format($c['cnt']);?></td></tr><?php endforeach;?></tbody>
  </table>
  <?php endif;?>

  <?php if(!empty($reactions)):?>
  <p style="font-weight:600;font-size:.83rem;color:#475569;margin-top:14px;margin-bottom:6px;">MESSAGE REACTIONS</p>
  <table class="rt">
    <thead><tr><th>Reaction</th><th>Count</th></tr></thead>
    <tbody><?php foreach($reactions as $rx):?><tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$rx['reaction_type'])));?></td><td><?php echo number_format($rx['cnt']);?></td></tr><?php endforeach;?></tbody>
  </table>
  <?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('intents',$sel_sects)):?>
<!-- ── SECTION 7: INTENTS ── -->
<div class="section" id="s-intents">
  <div class="st"><i class="fa-solid fa-bullseye"></i> Intent Classification Analysis</div>
  <?php if(!empty($intents)):?>
  <?php $int_max=max(array_merge(array_column($intents,'cnt'),[1]));?>
  <table class="rt">
    <thead><tr><th>#</th><th>Intent</th><th>Count</th><th>Distribution</th></tr></thead>
    <tbody><?php foreach($intents as $k=>$i):$pct=round($i['cnt']/$int_max*100);?><tr>
      <td style="color:#94a3b8;"><?php echo $k+1;?></td>
      <td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$i['nm'])));?></td>
      <td><?php echo number_format($i['cnt']);?></td>
      <td style="min-width:120px;"><div class="pbw"><div class="pbf fg" style="width:<?php echo $pct;?>%"><?php echo $pct;?>%</div></div></td>
    </tr><?php endforeach;?></tbody>
  </table>
  <?php else:?><p style="color:#64748b;">No intent data for the selected period.</p><?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('faq',$sel_sects)):?>
<!-- ── SECTION 8: TOP QUESTIONS ── -->
<div class="section" id="s-faq">
  <div class="st"><i class="fa-solid fa-book-open"></i> Top 10 Most Asked Questions</div>
  <?php if(!empty($faq_top)):?>
  <?php $fmax=max(array_merge(array_column($faq_top,'cnt'),[1]));?>
  <table class="rt">
    <thead><tr><th>#</th><th>Question</th><th>Times Asked</th><th>Relative Frequency</th></tr></thead>
    <tbody><?php foreach($faq_top as $k=>$f):$pct=round($f['cnt']/$fmax*100);?><tr>
      <td style="color:#94a3b8;font-weight:600;"><?php echo $k+1;?></td>
      <td><?php echo htmlspecialchars(mb_strimwidth($f['q']??'',0,100,'…'));?></td>
      <td><?php echo number_format($f['cnt']);?></td>
      <td style="min-width:120px;"><div class="pbw"><div class="pbf fg" style="width:<?php echo $pct;?>%"><?php echo $pct;?>%</div></div></td>
    </tr><?php endforeach;?></tbody>
  </table>
  <?php else:?><p style="color:#64748b;">No question data for the selected period.</p><?php endif;?>
</div>
<?php endif;?>

<?php if(in_array('graphs',$sel_sects)):?>
<!-- ── SECTION 9: GRAPHS ── -->
<div class="section" id="s-graphs">
  <div class="st"><i class="fa-solid fa-chart-line"></i> Daily Query Volume</div>
  <canvas id="chartDaily" height="90"></canvas>
</div>
<div class="section">
  <div class="st"><i class="fa-solid fa-chart-bar"></i> Queries by Hour of Day</div>
  <canvas id="chartHourly" height="90"></canvas>
</div>
<?php endif;?>

<?php if(in_array('health',$sel_sects)):?>
<!-- ── SECTION 10: SYSTEM HEALTH ── -->
<div class="section" id="s-health">
  <div class="st"><i class="fa-solid fa-server"></i> System Health & Operational Logs</div>
  <div class="kpi-grid">
    <div class="kc r"><div class="kl">Unresolved Errors</div><div class="kv"><?php echo number_format($err_unresolved);?></div></div>
    <div class="kc a"><div class="kl">Errors This Period</div><div class="kv"><?php echo number_format($err_total);?></div></div>
  </div>

  <div class="two-col">
    <div>
      <p style="font-weight:600;font-size:.83rem;color:#475569;margin-bottom:6px;">SYSTEM LOG LEVELS (PERIOD)</p>
      <?php if(!empty($sys_errors)):?>
      <table class="rt">
        <thead><tr><th>Level</th><th>Count</th></tr></thead>
        <tbody><?php foreach($sys_errors as $sl):
          $cls=['critical'=>'bsd','error'=>'bsd','warning'=>'bst','info'=>'bsa','debug'=>'bsi'][$sl['log_level']]??'bsi';
        ?><tr><td><span class="bs <?php echo $cls;?>"><?php echo htmlspecialchars(ucfirst($sl['log_level']));?></span></td><td><?php echo number_format($sl['cnt']);?></td></tr><?php endforeach;?></tbody>
      </table>
      <?php else:?><p style="color:#64748b;font-size:.85rem;">No system log data.</p><?php endif;?>
    </div>
    <div>
      <p style="font-weight:600;font-size:.83rem;color:#475569;margin-bottom:6px;">ADMIN ACTIVITY BY MODULE (PERIOD)</p>
      <?php if(!empty($admin_actions)):?>
      <table class="rt">
        <thead><tr><th>Module</th><th>Actions</th></tr></thead>
        <tbody><?php foreach($admin_actions as $aa):?><tr><td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$aa['module']??'–')));?></td><td><?php echo number_format($aa['cnt']);?></td></tr><?php endforeach;?></tbody>
      </table>
      <?php else:?><p style="color:#64748b;font-size:.85rem;">No admin activity data.</p><?php endif;?>
    </div>
  </div>
</div>
<?php endif;?>

</div><!-- /rpt-wrap -->
</div></div></div></div>

<script src="js/main.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Notification count
(function(){fetch('fetch_queries.php').then(r=>r.json()).then(d=>{const n=document.getElementById('not-yet-count');if(n){n.textContent=d.not_yet_count||0;n.style.display=d.not_yet_count>0?'inline':'none';}}).catch(()=>{});})();
setInterval(()=>fetch('fetch_queries.php').then(r=>r.json()).then(d=>{const n=document.getElementById('not-yet-count');if(n){n.textContent=d.not_yet_count||0;n.style.display=d.not_yet_count>0?'inline':'none';}}),60000);

<?php $dq=json_encode(array_values($daily));$hq=json_encode(array_values($hourly));?>
// Daily chart
const dc=document.getElementById('chartDaily')?.getContext('2d');
if(dc){const dq=<?php echo $dq;?>;const g=dc.createLinearGradient(0,0,0,200);g.addColorStop(0,'rgba(23,70,162,0.25)');g.addColorStop(1,'rgba(23,70,162,0)');
new Chart(dc,{type:'line',data:{labels:dq.map(d=>d.dt),datasets:[{label:'Messages',data:dq.map(d=>d.cnt),borderColor:'#1746a2',backgroundColor:g,fill:true,tension:.4,borderWidth:2.5,pointRadius:0,pointHoverRadius:5}]},options:{responsive:true,plugins:{legend:{labels:{color:'#64748b',font:{size:11}}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{color:'#64748b'}},x:{grid:{display:false},ticks:{color:'#94a3b8',font:{size:10},maxTicksLimit:14}}}}});}

// Hourly chart
const hc=document.getElementById('chartHourly')?.getContext('2d');
if(hc){const hq=<?php echo $hq;?>;const hd=Array(24).fill(0);hq.forEach(h=>hd[parseInt(h.hr)]=parseInt(h.cnt));
new Chart(hc,{type:'bar',data:{labels:Array.from({length:24},(_,i)=>`${i}:00`),datasets:[{label:'Messages',data:hd,backgroundColor:'rgba(23,70,162,0.75)',borderRadius:5,borderSkipped:false}]},options:{responsive:true,plugins:{legend:{labels:{color:'#64748b',font:{size:11}}}},scales:{y:{beginAtZero:true,grid:{color:'rgba(0,0,0,.04)'},ticks:{color:'#64748b'}},x:{grid:{display:false},ticks:{color:'#94a3b8',font:{size:10}},title:{display:true,text:'Hour of Day (0–23)',color:'#64748b'}}}}});}
</script>
</body></html>
