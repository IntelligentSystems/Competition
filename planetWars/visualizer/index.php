<!doctype html>
<html lang="en" class="no-js">
<head>
  <meta charset="utf-8">
  <!--[if IE]><![endif]-->

  <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
  <title>%PLAYER1% v.s. %PLAYER2% (%ROUND%)</title>
  <meta name="description" content="">
  <meta name="author" content="">
  <meta name="viewport" content="width=device-width; initial-scale=1.0; maximum-scale=1.0;">
  <link rel="stylesheet" href="css/style.css?v=1">
  <script src="js/modernizr-1.5.min.js"></script>
  
</head>
<!--[if lt IE 7 ]> <body class="ie6"> <![endif]-->
<!--[if IE 7 ]>    <body class="ie7"> <![endif]-->
<!--[if IE 8 ]>    <body class="ie8"> <![endif]-->
<!--[if IE 9 ]>    <body class="ie9"> <![endif]-->
<!--[if (gt IE 9)|!(IE)]><!--> <body> <!--<![endif]-->



  <div id="container">
    <header>
      <table id="players">
        <tr>
          <td style="color: rgb(204, 0, 0); text-decoration: initial;" width='40%' align="right" class="player1Name">%PLAYER1%</td>
          <td width='20%' align="center" class="playerVs">v.s.</td>
          <td style="color: rgb(119, 170, 204); text-decoration: initial;" width='40%' align="left" class="player2Name">%PLAYER2%</td>
        </tr>
      </table>
    </header>
    
    <div id="main">
        <canvas id="display" width="640" height="640"></canvas>
       
    </div>
    
    <footer>

    </footer>
  </div> <!-- end of #container -->

  <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
  <script>!window.jQuery && document.write('<script src="js/jquery-1.4.2.min.js"><\/script>')</script>
  <script>
  <?php
  $input = file_get_contents('input');
  echo 'var data = "' . str_replace("\n", "\\n", $input) . '"';
  ?>
  </script>
  <script src="js/visualizer.js?v=1"></script>

  <!--[if lt IE 7 ]>
    <script src="js/dd_belatedpng.js?v=1"></script>
  <![endif]-->  
</body>
</html>