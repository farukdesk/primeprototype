package bd.ac.primeuniversity.studentportal.ui.staff

import android.content.Intent
import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.GridLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.databinding.FragmentStaffDashboardBinding
import bd.ac.primeuniversity.studentportal.ui.dashboard.DashboardMenuAdapter
import bd.ac.primeuniversity.studentportal.ui.dashboard.Feature
import bd.ac.primeuniversity.studentportal.ui.dashboard.buildStaffDashboardMenu
import bd.ac.primeuniversity.studentportal.ui.notifications.NotificationsActivity
import bd.ac.primeuniversity.studentportal.ui.settings.SettingsActivity
import bd.ac.primeuniversity.studentportal.ui.staff.attendance.StaffAttendanceActivity
import bd.ac.primeuniversity.studentportal.ui.staff.leave.LeaveActivity
import bd.ac.primeuniversity.studentportal.ui.staff.leave.LeaveApprovalsActivity
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Staff home tab: header with the employee's name, type badge and a notices
 * bell (with unread badge), quick Today / Leave Balance cards and the
 * employee launcher menu (My Attendance, Leave Management, Notices, Profile).
 * Employees with leave approval access additionally see Leave Approvals.
 */
class StaffDashboardFragment : Fragment() {

    private var _binding: FragmentStaffDashboardBinding? = null
    private val binding get() = _binding!!

    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    /** Whether the current menu was built with the Leave Approvals entry. */
    private var menuHasApprovals = false

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentStaffDashboardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        // The hero header draws behind the transparent status bar – pad it
        // down by the status-bar height so its content stays visible.
        ViewCompat.setOnApplyWindowInsetsListener(binding.header) { v, insets ->
            val top = insets.getInsets(WindowInsetsCompat.Type.statusBars()).top
            v.updatePadding(top = v.paddingBottom + top)
            insets
        }

        // 2-column launcher grid; section headers span the full width.
        val layoutManager = GridLayoutManager(requireContext(), 2)
        layoutManager.spanSizeLookup = object : GridLayoutManager.SpanSizeLookup() {
            override fun getSpanSize(position: Int): Int {
                val adapter = binding.menuList.adapter as? DashboardMenuAdapter
                return if (adapter?.isHeader(position) != false) 2 else 1
            }
        }
        binding.menuList.layoutManager = layoutManager
        binding.menuList.adapter =
            DashboardMenuAdapter(buildStaffDashboardMenu(), grid = true) { onFeature(it) }

        binding.btnBell.setOnClickListener {
            (activity as? StaffMainActivity)?.selectTab(R.id.nav_notices)
        }

        // Attendance is recorded by the campus clock devices; the button
        // opens My Attendance where today's in/out times are listed.
        binding.btnClock.setOnClickListener {
            startActivity(Intent(requireContext(), StaffAttendanceActivity::class.java))
        }

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info, R.color.cat_campus
        )
        binding.swipeRefresh.setOnRefreshListener { refreshSession() }

        app.currentStaff.observe(viewLifecycleOwner) { render(it) }
    }

    private fun onFeature(feature: Feature) {
        when (feature) {
            Feature.MY_ATTENDANCE ->
                startActivity(Intent(requireContext(), StaffAttendanceActivity::class.java))
            Feature.LEAVE_MANAGEMENT ->
                startActivity(Intent(requireContext(), LeaveActivity::class.java))
            Feature.LEAVE_APPROVALS ->
                startActivity(Intent(requireContext(), LeaveApprovalsActivity::class.java))
            Feature.STAFF_NOTICES ->
                (activity as? StaffMainActivity)?.selectTab(R.id.nav_notices)
            Feature.ANNOUNCEMENTS ->
                startActivity(Intent(requireContext(), NotificationsActivity::class.java))
            Feature.STAFF_PROFILE ->
                (activity as? StaffMainActivity)?.selectTab(R.id.nav_profile)
            Feature.SETTINGS -> openSettings()
            Feature.THEME -> openSettings(openTheme = true)
            else -> Unit
        }
    }

    private fun openSettings(openTheme: Boolean = false) {
        val intent = Intent(requireContext(), SettingsActivity::class.java)
            .putExtra(SettingsActivity.EXTRA_OPEN_THEME, openTheme)
        startActivity(intent)
    }

    private fun render(me: StaffMeResponse?) {
        binding.staffName.text = me?.user?.fullName?.takeIf { it.isNotBlank() }
            ?: getString(R.string.employee)

        val meta = listOfNotNull(
            me?.employee?.designation?.takeIf { it.isNotBlank() },
            me?.employee?.department?.takeIf { it.isNotBlank() },
        ).joinToString(" \u00b7 ")
        binding.staffMeta.text = meta
        binding.staffMeta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE

        val typeLabel = me?.employee?.employeeTypeLabel
        binding.staffTypeBadge.text = typeLabel ?: ""
        binding.staffTypeBadge.visibility =
            if (typeLabel.isNullOrBlank()) View.GONE else View.VISIBLE

        // Show the Leave Approvals launcher entry only for employees whose
        // user group is part of an active leave approval flow.
        val canApprove = me?.permissions?.canApproveLeaves == true
        if (canApprove != menuHasApprovals) {
            menuHasApprovals = canApprove
            binding.menuList.adapter =
                DashboardMenuAdapter(buildStaffDashboardMenu(canApprove), grid = true) {
                    onFeature(it)
                }
        }

        renderBadge(me?.stats?.noticesUniversity ?: 0)

        val dash = getString(R.string.dash)
        val today = me?.today
        binding.todayValue.text =
            "In ${today?.inTime ?: dash} \u00b7 Out ${today?.outTime ?: dash}"
        val clockedIn = !today?.inTime.isNullOrBlank() && today?.outTime.isNullOrBlank()
        binding.btnClock.setText(if (clockedIn) R.string.clock_out else R.string.clock_in)

        val lb = me?.leaveBalance
        binding.casualPill.text = getString(
            R.string.leave_pill_casual,
            lb?.let { fmt(it.casualRemaining) } ?: dash,
            lb?.let { fmt(it.casualTotal) } ?: dash,
        )
        binding.sickPill.text = getString(
            R.string.leave_pill_sick,
            lb?.let { fmt(it.sickRemaining) } ?: dash,
            lb?.let { fmt(it.sickTotal) } ?: dash,
        )
    }

    /**
     * Shows only the notices the employee has NOT seen yet. Previously the
     * badge showed the fixed total notice count forever, even after reading.
     */
    private fun renderBadge(total: Int) {
        val storage = app.repository.storage
        if (total < storage.seenStaffNotices) storage.seenStaffNotices = total
        val unread = (total - storage.seenStaffNotices).coerceAtLeast(0)
        binding.bellBadge.text = if (unread > 99) "99+" else unread.toString()
        binding.bellBadge.visibility = if (unread > 0) View.VISIBLE else View.GONE
    }

    /** Re-evaluates the bell badge after the Notices tab marks notices as seen. */
    fun refreshBadge() {
        if (_binding == null) return
        renderBadge(app.currentStaff.value?.stats?.noticesUniversity ?: 0)
    }

    private fun fmt(v: Double): String =
        if (v % 1.0 == 0.0) v.toInt().toString() else v.toString()

    private fun refreshSession() {
        lifecycleScope.launch {
            when (val result = app.repository.staffMe()) {
                is AppResult.Success -> app.setStaffSession(result.data)
                is AppResult.Error -> Unit
            }
            _binding?.swipeRefresh?.isRefreshing = false
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
