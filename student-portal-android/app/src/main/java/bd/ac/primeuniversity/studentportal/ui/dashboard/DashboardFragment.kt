package bd.ac.primeuniversity.studentportal.ui.dashboard

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.core.content.ContextCompat
import androidx.core.graphics.ColorUtils
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.Stats
import bd.ac.primeuniversity.studentportal.data.model.Student
import bd.ac.primeuniversity.studentportal.databinding.FragmentDashboardBinding
import bd.ac.primeuniversity.studentportal.ui.main.MainActivity
import bd.ac.primeuniversity.studentportal.ui.notices.NoticeAdapter
import bd.ac.primeuniversity.studentportal.ui.notices.NoticeDetailActivity
import bd.ac.primeuniversity.studentportal.ui.settings.SettingsActivity
import bd.ac.primeuniversity.studentportal.util.AppResult
import bd.ac.primeuniversity.studentportal.util.Formatters
import kotlinx.coroutines.launch

/** Home tab: welcome header, quick stats and the three most recent notices. */
class DashboardFragment : Fragment() {

    private var _binding: FragmentDashboardBinding? = null
    private val binding get() = _binding!!

    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }
    private val adapter = NoticeAdapter(showPreview = false) { openNotice(it) }

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentDashboardBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.recentNotices.layoutManager = LinearLayoutManager(requireContext())
        binding.recentNotices.adapter = adapter

        binding.btnSettings.setOnClickListener {
            startActivity(android.content.Intent(requireContext(), SettingsActivity::class.java))
        }
        val goNotices = View.OnClickListener {
            (activity as? MainActivity)?.selectTab(R.id.nav_notices)
        }
        binding.viewAll.setOnClickListener(goNotices)

        // Stat cards static labels/icons
        binding.statNotices.statLabel.text = getString(R.string.stat_notices)
        binding.statNotices.statIcon.setImageResource(R.drawable.ic_notifications)
        binding.statOutstanding.statLabel.text = getString(R.string.stat_outstanding)
        binding.statOutstanding.statIcon.setImageResource(R.drawable.ic_wallet)

        app.currentStudent.observe(viewLifecycleOwner) { renderStudent(it) }
        app.currentStats.observe(viewLifecycleOwner) { renderStats(it) }

        binding.swipeRefresh.setOnRefreshListener { loadRecentNotices(fromSwipe = true) }
        loadRecentNotices()
    }

    private fun renderStudent(student: Student?) {
        binding.studentName.text = student?.fullName?.takeIf { it.isNotBlank() }
            ?: getString(R.string.student)
    }

    private fun renderStats(stats: Stats?) {
        val notices = stats?.noticesUniversity ?: 0
        tint(binding.statNotices.iconContainer, R.color.info)
        binding.statNotices.statValue.text = notices.toString()
        binding.statNotices.statValue.setTextColor(color(R.color.info))

        val outstanding = stats?.outstandingBalance
        val positive = outstanding != null && outstanding > 0
        val colorRes = if (positive) R.color.error else R.color.success
        tint(binding.statOutstanding.iconContainer, colorRes)
        binding.statOutstanding.statValue.setTextColor(color(colorRes))
        binding.statOutstanding.statValue.text =
            if (outstanding != null) Formatters.moneyWhole(outstanding) else getString(R.string.dash)
    }

    private fun loadRecentNotices(fromSwipe: Boolean = false) {
        if (!fromSwipe) binding.noticesProgress.visibility = View.VISIBLE
        binding.noticesEmpty.visibility = View.GONE
        lifecycleScope.launch {
            val result = app.repository.getNotices("university", 1)
            binding.noticesProgress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
            when (result) {
                is AppResult.Success -> {
                    val list = result.data.notices.take(3)
                    adapter.submitList(list)
                    binding.noticesEmpty.visibility =
                        if (list.isEmpty()) View.VISIBLE else View.GONE
                }
                is AppResult.Error -> {
                    if (adapter.itemCount == 0) binding.noticesEmpty.visibility = View.VISIBLE
                }
            }
        }
    }

    private fun openNotice(notice: bd.ac.primeuniversity.studentportal.data.model.Notice) {
        startActivity(NoticeDetailActivity.intent(requireContext(), notice.id, notice.type))
    }

    private fun tint(view: View, colorRes: Int) {
        val base = color(colorRes)
        view.background?.mutate()?.setTint(ColorUtils.setAlphaComponent(base, 30))
    }

    private fun color(res: Int) = ContextCompat.getColor(requireContext(), res)

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
