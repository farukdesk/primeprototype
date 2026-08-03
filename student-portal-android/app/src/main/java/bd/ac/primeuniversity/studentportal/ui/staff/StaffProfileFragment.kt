package bd.ac.primeuniversity.studentportal.ui.staff

import android.os.Bundle
import android.util.TypedValue
import android.view.Gravity
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.LinearLayout
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import androidx.fragment.app.Fragment
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.StaffMeResponse
import bd.ac.primeuniversity.studentportal.databinding.FragmentStaffProfileBinding

/** Staff profile tab: employee details and logout. */
class StaffProfileFragment : Fragment() {

    private var _binding: FragmentStaffProfileBinding? = null
    private val binding get() = _binding!!

    private val app: PrimeApp by lazy { requireActivity().application as PrimeApp }

    override fun onCreateView(
        inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?
    ): View {
        _binding = FragmentStaffProfileBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        binding.btnLogout.setOnClickListener { confirmLogout() }
        app.currentStaff.observe(viewLifecycleOwner) { render(it) }
    }

    private fun render(me: StaffMeResponse?) {
        val user = me?.user
        binding.avatar.text = user?.initials ?: "?"

        // Employee profile photo (uploaded via Employee/Faculty Profiles in the
        // admin panel); the initials avatar stays visible as the fallback.
        val photoUrl = user?.photoUrl
        if (photoUrl.isNullOrBlank()) {
            binding.avatarPhoto.visibility = View.GONE
        } else {
            binding.avatarPhoto.visibility = View.VISIBLE
            com.bumptech.glide.Glide.with(this)
                .load(photoUrl)
                .circleCrop()
                .into(binding.avatarPhoto)
        }

        binding.name.text = user?.fullName?.takeIf { it.isNotBlank() }
            ?: getString(R.string.employee)

        val meta = listOfNotNull(
            user?.group?.takeIf { it.isNotBlank() },
            user?.username?.takeIf { it.isNotBlank() },
        ).joinToString(" \u00b7 ")
        binding.meta.text = meta
        binding.meta.visibility = if (meta.isBlank()) View.GONE else View.VISIBLE

        val typeLabel = me?.employee?.employeeTypeLabel
        binding.typeBadge.text = typeLabel ?: ""
        binding.typeBadge.visibility =
            if (typeLabel.isNullOrBlank()) View.GONE else View.VISIBLE

        binding.rows.removeAllViews()
        val e = me?.employee

        addSection(getString(R.string.section_employment), listOf(
            getString(R.string.lbl_employee_id) to e?.employeeId,
            getString(R.string.lbl_designation) to e?.designation,
            getString(R.string.lbl_department) to e?.department,
            getString(R.string.lbl_job_type) to e?.jobType,
            getString(R.string.lbl_joining_date) to e?.joiningDate,
            getString(R.string.lbl_status) to e?.employeeStatus,
        ))

        addSection(getString(R.string.section_contact), listOf(
            getString(R.string.lbl_phone) to e?.phone,
            getString(R.string.lbl_email) to user?.email,
        ))

        addSection(getString(R.string.section_personal), listOf(
            getString(R.string.lbl_father_name) to e?.fatherName,
            getString(R.string.lbl_mother_name) to e?.motherName,
            getString(R.string.lbl_gender) to e?.gender,
            getString(R.string.lbl_date_of_birth) to e?.dateOfBirth,
            getString(R.string.lbl_blood_group) to e?.bloodGroup,
            getString(R.string.lbl_religion) to e?.religion,
            getString(R.string.lbl_national_id) to e?.nationalId,
            getString(R.string.lbl_nationality) to e?.nationality,
            getString(R.string.lbl_birth_place) to e?.birthPlace,
        ))

        // Faculty employees additionally see their academic profile; for
        // administrative employees the server sends faculty = null, so only
        // the options available for their type are shown.
        val f = me?.faculty
        if (e?.isFaculty == true && f != null) {
            addSection(getString(R.string.section_faculty), listOf(
                getString(R.string.lbl_faculty_designation) to f.designation,
                getString(R.string.lbl_academic_department) to f.academicDepartment,
                getString(R.string.lbl_official_email) to f.officialEmail,
                getString(R.string.lbl_office) to f.office,
                getString(R.string.lbl_office_hours) to f.officeHours,
                getString(R.string.lbl_qualification) to f.qualification,
                getString(R.string.lbl_research_interest) to f.researchInterest,
            ))
        }
    }

    /** Adds a titled group of rows, skipped entirely when every value is blank. */
    private fun addSection(title: String, rows: List<Pair<String, String?>>) {
        if (rows.all { it.second.isNullOrBlank() }) return
        addHeader(title)
        rows.forEach { (label, value) -> addRow(label, value) }
    }

    private fun addHeader(title: String) {
        val ctx = requireContext()
        binding.rows.addView(TextView(ctx).apply {
            text = title
            textSize = 12f
            isAllCaps = true
            letterSpacing = 0.08f
            setTypeface(typeface, android.graphics.Typeface.BOLD)
            setTextColor(androidx.core.content.ContextCompat.getColor(ctx, R.color.primary))
            setPadding(dp(16), dp(18), dp(16), dp(4))
        })
    }

    private fun addRow(label: String, value: String?) {
        if (value.isNullOrBlank()) return
        val ctx = requireContext()

        val secondary = TypedValue().let { tv ->
            ctx.theme.resolveAttribute(android.R.attr.textColorSecondary, tv, true)
            androidx.core.content.ContextCompat.getColorStateList(ctx, tv.resourceId)
        }

        val row = LinearLayout(ctx).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(dp(16), dp(10), dp(16), dp(10))
            gravity = Gravity.CENTER_VERTICAL
        }
        row.addView(TextView(ctx).apply {
            text = label
            textSize = 14f
            secondary?.let { setTextColor(it) }
            layoutParams = LinearLayout.LayoutParams(0, ViewGroup.LayoutParams.WRAP_CONTENT, 1f)
        })
        row.addView(TextView(ctx).apply {
            text = value
            textSize = 14f
            gravity = Gravity.END
            setTypeface(typeface, android.graphics.Typeface.BOLD)
        })
        binding.rows.addView(row)
    }

    private fun dp(value: Int): Int =
        (value * resources.displayMetrics.density).toInt()

    private fun confirmLogout() {
        AlertDialog.Builder(requireContext())
            .setTitle(R.string.staff_logout)
            .setMessage(R.string.staff_logout_confirm)
            .setPositiveButton(R.string.staff_logout) { _, _ ->
                (activity as? StaffMainActivity)?.logout()
            }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }
}
