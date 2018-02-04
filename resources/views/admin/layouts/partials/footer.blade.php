<!-- Main Footer -->
<footer class="main-footer @hasrole('admin') sidebar-in @endhasrole">
    <!-- To the right -->
    <div class="pull-right hidden-xs">
        <a href="http://whitebikes.info"><strong>WhiteBikes</strong></a>. Open source bike sharing system.
        <span><strong>date:</strong> {{ $date }}</span> <span><strong>version:</strong> {{ $version }}</span>
    </div>
    <!-- Default to the left -->
    <strong>Copyright &copy; {{ date('Y') }} <a href="http://whitebikes.info">WhiteBikes</a>.</strong> Created by <a href="https://github.com/cyklokoalicia/OpenSourceBikeShare/graphs/contributors">Contributors</a>. See code at <a href="https://github.com/cyklokoalicia/OpenSourceBikeShare">Github</a>
</footer>
